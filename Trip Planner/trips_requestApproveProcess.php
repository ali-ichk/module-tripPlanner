<?php

use Gibbon\Services\Format;
use Gibbon\Comms\NotificationEvent;
use Gibbon\Comms\NotificationSender;
use Gibbon\Domain\System\NotificationGateway;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Module\TripPlanner\Domain\ApproverGateway;
use Gibbon\Module\TripPlanner\Domain\TripGateway;
use Gibbon\Module\TripPlanner\Domain\TripLogGateway;

$_POST['address'] = '/modules/Trip Planner/trips_manage.php';

require_once '../../gibbon.php';
require_once "./moduleFunctions.php";

$absoluteURL = $session->get('absoluteURL');
$moduleName = $session->get('module');
$URL = $absoluteURL . '/index.php?q=/modules/' . $moduleName;

$gibbonPersonID = $session->get('gibbonPersonID');
$personName = Format::name('', $session->get('preferredName'), $session->get('surname'), 'Staff', false, true);

$approverGateway = $container->get(ApproverGateway::class);
$approver = $approverGateway->selectApproverByPerson($gibbonPersonID);
$isApprover = !empty($approver);
$finalApprover = $isApprover ? $approver['finalApprover'] : false;

if (!isActionAccessible($guid, $connection2, '/modules/Trip Planner/trips_manage.php') || !$isApprover) {
    //Acess denied
    $URL .= '/trips_manage.php&return=error0';
    header("Location: {$URL}");
    exit();
} else {
    $tripPlannerRequestID = $_POST['tripPlannerRequestID'] ?? '';

    $tripGateway = $container->get(TripGateway::class);
    $trip = $tripGateway->getByID($tripPlannerRequestID);

    if (!empty($trip)) {
        $settingGateway = $container->get(SettingGateway::class);
        $riskAssessmentApproval = $settingGateway->getSettingByScope('Trip Planner', 'riskAssessmentApproval');

        $title = $trip['title'];
        $status = $trip['status'];
        $owner = $trip['creatorPersonID'];

        // Approver cannot approve their own trip
        if ($owner == $session->get('gibbonPersonID')) {
            $URL .= '/trips_manage.php&return=error1';
            header("Location: {$URL}");
            exit();
        }

        if (needsApproval($container, $gibbonPersonID, $tripPlannerRequestID)) {
            $URL .= '/trips_requestApprove.php&tripPlannerRequestID=' . $tripPlannerRequestID;

            $action = $_POST['action'] ?? '';
            $comment = $_POST['comment'] ?? '';

            if (empty($action) || (empty($comment) && $action == 'Comment')) {
                $URL .= '&return=error1';
                header("Location: {$URL}");
                exit();
            }
            //TODO: Transaction?

            $notificationGateway = $container->get(NotificationGateway::class);
            $notificationSender = new NotificationSender($notificationGateway, $session);

            $notificationURL = '/index.php?q=/modules/' . $moduleName . '/trips_requestView.php&tripPlannerRequestID=' . $tripPlannerRequestID;
            $commentText = !empty($comment) ? '<br/><br/><b>'.__('Comment').':</b><br/>'.$comment : '';

            $tripLogGateway = $container->get(TripLogGateway::class);

            if ($action == 'Approval') {
                if($status == 'Awaiting Final Approval') {
                    $action .= ' - Final';

                    if (!$tripGateway->update($tripPlannerRequestID, ['status' => 'Approved'])) {
                        $URL .= '&return=error2';
                        header("Location: {$URL}");
                        exit();
                    }

                    if ($owner != $gibbonPersonID) {
                        $notificationSender->addNotification($owner, __('Your trip request has been fully approved by {person}.', ['person' => $personName]).$commentText, $moduleName, $notificationURL);
                    }
                } else {
                    $done = false;
                    $requestApprovalType = $settingGateway->getSettingByScope('Trip Planner', 'requestApprovalType');

                    if ($requestApprovalType == 'One Of') {
                        $done = true;
                    } elseif ($requestApprovalType == 'Two Of') {
                        $approvalLog = $tripLogGateway->selectBy([
                            'tripPlannerRequestID' => $trip['tripPlannerRequestID'],
                            'action' => 'Approval - Partial'
                        ]);

                        $done = $approvalLog->rowCount() >= 1;
                    } elseif ($requestApprovalType == 'Chain Of All') {
                        $nextApprover = $approverGateway->selectNextApprover($tripPlannerRequestID, $gibbonPersonID);
                        $done = $nextApprover->rowCount() == 0;
                    }

                    if ($done) {
                        if($riskAssessmentApproval) {
                            $status = 'Awaiting Final Approval';
                            $action .= ' - Partial';
                        } else {
                            $action .= ' - Final';
                            $status = 'Approved';
                        }

                        if (!$tripGateway->update($tripPlannerRequestID, ['status' => $status])) {
                            $URL .= '&return=error2';
                            header("Location: {$URL}");
                            exit();
                        }

                        if ($status == 'Approved') {
                            //Custom notifications for final approval
                            $event = new NotificationEvent('Trip Planner', 'Trip Request Approval');

                            $notificationText = __('A trip request has been approved by {person}: {trip}', ['person' => $personName, 'trip' => $trip['title']]);
                            $event->setNotificationText($notificationText);
                            $event->setActionLink($notificationURL);
                            $event->sendNotifications($pdo, $session);
                            $message = __('Your trip request has been fully approved by {person}.', ['person' => $personName]).$commentText;
                        } else {
                            $message = __('Your trip request has been partially approved by {person} and is awaiting final approval.', ['person' => $personName]).$commentText;
                        }

                        if ($owner != $gibbonPersonID) {
                            $notificationSender->addNotification($owner, $message, $moduleName, $notificationURL);
                        }

                    } elseif (!empty($nextApprover) && $nextApprover->isNotEmpty()) {
                        $action .= ' - Partial';
                        $nextApprover = $nextApprover->fetch();

                        $notificationSender->addNotification($nextApprover['gibbonPersonID'], __('A trip request is awaiting your approval.'), $moduleName, $absoluteURL . '/index.php?q=/modules/' . $moduleName . '/trips_requestApprove.php&tripPlannerRequestID='. $tripPlannerRequestID);

                        if ($owner != $gibbonPersonID) {
                            $notificationSender->addNotification($owner, __('Your trip request has been partially approved by {person} and is awaiting final approval.', ['person' => $personName]).$commentText, $moduleName, $notificationURL);
                        }
                    } else {
                        $action .= ' - Partial';

                        if ($owner != $gibbonPersonID) {
                            $notificationSender->addNotification($owner, __('Your trip request has been partially approved by {person} and is awaiting final approval.', ['person' => $personName]).$commentText, $moduleName, $notificationURL);
                        }
                    }
                }
            } elseif ($action == 'Rejection') {
                if (!$tripGateway->update($tripPlannerRequestID, ['status' => 'Rejected'])) {
                    $URL .= '&return=error2';
                    header("Location: {$URL}");
                    exit();
                }

                if ($owner != $gibbonPersonID) {
                    $notificationSender->addNotification($owner, __('Your trip request has been rejected by {person}. Please see their comments for more details.', ['person' => $personName]).$commentText, $moduleName, $notificationURL);
                }
            } elseif ($action == 'Comment') {
                tripCommentNotifications($tripPlannerRequestID, $gibbonPersonID, $personName, $tripLogGateway, $trip, $comment, $notificationSender);
            } elseif ($action == 'Pre-Approval') {
                if (!$tripGateway->update($tripPlannerRequestID, ['status' => 'Pre-Approved'])) {
                    $URL .= '&return=error2';
                    header("Location: {$URL}");
                    exit();
                }
                
                if ($owner != $gibbonPersonID) {
                    $notificationSender->addNotification($owner, __('Your trip request has been pre-approved by {person}.', ['person' => $personName]).$commentText, $moduleName, $notificationURL);
                }
            } else {
                $URL .= '&return=error1';
                header("Location: {$URL}");
                exit();
            }

            $tripPlannerRequestLogID = $tripLogGateway->insert([
                'tripPlannerRequestID' => $tripPlannerRequestID,
                'gibbonPersonID' => $gibbonPersonID,
                'action' => $action,
                'comment' => $comment
            ]);
           
            if (!$tripPlannerRequestLogID) {
                $URL .= '&return=error2';
                header("Location: {$URL}");
                exit();
            }

            //Send notifications
            $notificationSender->sendNotifications();

            //When PHP 8 is supported, replace this with str_starts_with
            $approval = 'Approval';
            if (substr($action, 0, strlen($approval)) == $approval) {
                $URL = $absoluteURL . '/index.php?q=/modules/' . $moduleName . '/trips_manage.php';
            }

            $URL .= '&return=success0';
            header("Location: {$URL}");
            exit();
        } else {
            $URL .= '/trips_manage.php&return=error1';
            header("Location: {$URL}");
            exit();
        }
    } else {
        $URL .= '/trips_manage.php&return=error1';
        header("Location: {$URL}");
        exit();
    }
}
?>
