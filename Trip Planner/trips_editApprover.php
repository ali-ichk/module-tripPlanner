<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community (https://gibbonedu.org/about/)
Copyright © 2010, Gibbon Foundation
Gibbon™, Gibbon Education Ltd. (Hong Kong)

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

use Gibbon\Forms\Form;
use Gibbon\Forms\DatabaseFormFactory;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Module\TripPlanner\Domain\ApproverGateway;

if (!isActionAccessible($guid, $connection2, '/modules/Trip Planner/trips_editApprover.php')) {
    //Acess denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $approverGateway = $container->get(ApproverGateway::class);

    $tripPlannerApproverID = $_GET['tripPlannerApproverID'] ?? '';
    $approver = $approverGateway->getByID($tripPlannerApproverID);

    if (!empty($approver)) {
        $form = Form::create('editApprover', $session->get('absoluteURL') . '/modules/' . $session->get('module') . '/trips_editApproverProcess.php', 'post');
        $form->addHiddenValue('address', $session->get('address'));
        $form->addHiddenValue('tripPlannerApproverID', $tripPlannerApproverID);
        $form->setFactory(DatabaseFormFactory::create($pdo));
        $form->setTitle('Edit Approver');

        $row = $form->addRow();
            $row->addLabel('gibbonPersonID', 'Staff');
            $row->addSelectPerson('gibbonPersonID')
                ->fromArray($approverGateway->selectStaffForApprover(false))
                ->selected($approver['gibbonPersonID'])
                ->disabled();

        $riskAssessmentApproval = $container->get(SettingGateway::class)->getSettingByScope('Trip Planner', 'riskAssessmentApproval');
        if($riskAssessmentApproval) {
            $row = $form->addRow();
                $row->addLabel('finalApprover', 'Final Approver');
                $row->addCheckbox('finalApprover')
                    ->checked(boolval($approver['finalApprover']));
        }

        $row = $form->addRow();
            $row->addSubmit();

        print $form->getOutput();
    } else {
        $page->addError(__('Invalid Approver.'));
    }
}   
?>
