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

use Gibbon\Domain\Activities\ActivityGateway;
use Gibbon\Domain\Messenger\GroupGateway;
use Gibbon\Domain\Staff\StaffGateway;
use Gibbon\Domain\Students\StudentGateway;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Domain\Timetable\CourseGateway;
use Gibbon\Forms\Form;
use Gibbon\Module\TripPlanner\Domain\RiskTemplateGateway;
use Gibbon\Module\TripPlanner\Domain\TripCostGateway;
use Gibbon\Module\TripPlanner\Domain\TripDayGateway;
use Gibbon\Module\TripPlanner\Domain\TripGateway;
use Gibbon\Module\TripPlanner\Domain\TripPersonGateway;
use Gibbon\Services\Format;
use Gibbon\Http\Url;

require_once __DIR__ . '/moduleFunctions.php';

//Checking if editing mode should be enabled
$edit = false;
$prefix = 'Submit';

$mode = $_REQUEST['mode'] ?? '';
$tripPlannerRequestID = $_REQUEST['tripPlannerRequestID'] ?? '';

//Check if a mode and id are given
if (!empty($mode) && !empty($tripPlannerRequestID)) {
    //Get trip from gateway
    $tripGateway = $container->get(TripGateway::class);
    $trip = $tripGateway->getByID($tripPlannerRequestID);    

    //If the trip exists, set to edit mode
    if (!empty($trip)) {
        $edit = true;
        $prefix = 'Edit';
    }
}

$isDraft = !empty($trip) && $trip['status'] == 'Draft';

$page->breadcrumbs->add(__($prefix . ' Trip Request'));

$gibbonPersonID = $session->get('gibbonPersonID');
$highestAction = getHighestGroupedAction($guid, '/modules/Trip Planner/trips_manage.php', $connection2);

if (!isActionAccessible($guid, $connection2, '/modules/Trip Planner/trips_submitRequest.php') || ($edit && $highestAction != 'Manage Trips_full' && $trip['creatorPersonID'] != $gibbonPersonID)) {
//If the action isn't accesible, or in edit mode and the current user isn't the owner, throw error.
    $page->addError(__('You do not have access to this action.'));
} else if ((isset($trip) && empty($trip)) || (!empty($mode) && !$edit)) {
    //If a trip is provided, but doesn't exit, Or the mode is set, but edit isn't enabled, throw error.
    $page->addError(__('Invalid Trip.'));
} else {
    $moduleName = $session->get('module');
    $gibbonSchoolYearID = $session->get('gibbonSchoolYearID');

    $settingGateway = $container->get(SettingGateway::class);
    $riskTemplateGateway = $container->get(RiskTemplateGateway::class);

    //Return Message
    $page->return->addReturns([
        'warning3' => __m('Your request was successful, but some required fields were missing. Please update your trip data.'),
        'warning4' => __m('Your request was successful, but there are no dates set for this trip. Please add dates and update your trip request.'),
        'warning5' => __m('Your request was successful, but there was a problem saving the cost details. Please check the costs and update your trip request.'),
        'warning6' => __m('Your request was successful, but there no people have been added to the trip. Please check the teacher and student list and update your trip request.'),
        'warning7' => __m('Your request was successful, but there was data missing in the dates for this trip. Please check the dates and update your trip request.'),
        'success2' => __m('A trip plan for your Deep Learning experience has been created below. It has been added as a Draft, please review and update the details and submit your request for approval when ready.'),
    ]);
    if(!$edit && !empty($tripPlannerRequestID)) {
        $page->return->setEditLink($session->get('absoluteURL') . '/index.php?q=/modules/' . $moduleName . '/trips_requestView.php&tripPlannerRequestID=' . $tripPlannerRequestID);
    }

    //Templates
    $defaultRiskTemplate = $settingGateway->getSettingByScope('Trip Planner', 'defaultRiskTemplate');
    
    $templateNames = [
        '-1' => 'None',
        '0' => 'Custom'
    ];

    $templates = [
        '-1' => null,
        '0' => $settingGateway->getSettingByScope('Trip Planner', 'riskAssessmentTemplate')
    ];
    
    $criteria = $riskTemplateGateway->newQueryCriteria()
        ->sortBy(['name', 'tripPlannerRiskTemplateID']);

    $riskTemplates = $riskTemplateGateway->queryTemplates($criteria);
    foreach ($riskTemplates as $riskTemplate) {
        $templateNames[$riskTemplate['tripPlannerRiskTemplateID']] = $riskTemplate['name'];
        $templates[$riskTemplate['tripPlannerRiskTemplateID']] = $riskTemplate['body'];
    }

    //Add By Groups
    $courseGateway = $container->get(CourseGateway::class);
    $activityGateway = $container->get(ActivityGateway::class);
    $groupGateway = $container->get(GroupGateway::class);

    //TODO: Honestly, this whole bodge should really just be its own Gateway method at this point, but I can't be botherd rn. If, for some reason, you feel like doing that, be my guest.
    $courseCriteria = $courseGateway->newQueryCriteria()
        ->filterBy('bodge', 'This is very hacky')
        ->addFilterRule('bodge', function ($query, $bodge) {
            return $query->resetCols()
                ->cols(['gibbonCourseClass.gibbonCourseClassID', 'gibbonCourse.name', 'gibbonCourse.nameShort', 'gibbonCourseClass.nameShort as classNameShort'])
                ->resetGroupBy();
        })
        ->sortBy(['nameShort', 'classNameShort']);

    $activityCriteria = $activityGateway->newQueryCriteria()
        ->sortBy(['name']);;

    $groupCriteria = $groupGateway->newQueryCriteria()
        ->sortBy(['name']);

    $highestAction = getHighestGroupedAction($guid, '/modules/Trip Planner/trips_submitRequest.php', $connection2);
    if ($highestAction == 'Submit Request_all') {
        //Query all classes
        $classes = $courseGateway->queryCoursesBySchoolYear($courseCriteria, $gibbonSchoolYearID);

        //Disable Messenger Group filtering by Owner
        $gibbonPersonIDOwner = null;
    } else {
        //Query Classes run by person
        $classes = $courseGateway->queryCoursesByDepartmentStaff($courseCriteria, $gibbonSchoolYearID, $gibbonPersonID);

        //Filter Activities by user who organises them
        $activityCriteria->filterBy('role', ['role' => 'Organiser', 'gibbonPersonID' => $gibbonPersonID]);
        $activityCriteria->addFilterRule('role', function ($query, $role){
            return $query->leftJoin('gibbonActivityStaff', 'gibbonActivityStaff.gibbonActivityID = gibbonActivity.gibbonActivityID')
                ->where('gibbonActivityStaff.role = :role')
                ->where('gibbonActivityStaff.gibbonPersonID = :gibbonPersonID')
                ->bindValues($role);
        });

        //Set Messenger Group filtering
        $gibbonPersonIDOwner = $gibbonPersonID;
    }

    //Collect and Proccess all group data
    $addGroups = array_filter([
        'By Class' => array_reduce($classes->toArray(), function ($array, $course) {
            $array['Class:' . $course['gibbonCourseClassID']] = htmlPrep($course['nameShort']) . '.' . htmlPrep($course['classNameShort']) . ' - ' . htmlPrep($course['name']);
            return $array;
        }),

        'By Activity' => array_reduce($activityGateway->queryActivitiesBySchoolYear($activityCriteria, $gibbonSchoolYearID)->toArray(), function ($array, $activity) {
            $array['Activity:' . $activity['gibbonActivityID']] = htmlPrep($activity['name']);
            return $array;
        }),
        
        'By Group' => array_reduce($groupGateway->queryGroups($groupCriteria, $gibbonSchoolYearID, $gibbonPersonIDOwner)->toArray(), function ($array, $group) {
            $array['Group:' . $group['gibbonGroupID']] = htmlPrep($group['name']);
            return $array;
        })
    ]);

    //Trip People Data for Staff and Student data processing
    $tripPeople = [];
    if ($edit) {
        $tripPersonGateway = $container->get(TripPersonGateway::class);
        $tripPersonCriteria = $tripPersonGateway->newQueryCriteria()
            ->filterBy('tripPlannerRequestID', $tripPlannerRequestID);

        $tripPeople = $tripPersonGateway->queryTripPeople($tripPersonCriteria)->getColumn('gibbonPersonID');
    }

    //Staff/Teacher Data for Multi-Select
    $staffGateway = $container->get(StaffGateway::class);
    $staffCriteria = $staffGateway->newQueryCriteria()
        ->sortBy(['surname', 'preferredName']);

    $teachers = array_reduce($staffGateway->queryAllStaff($staffCriteria)->toArray(), function ($array, $staff) use ($tripPeople) {
        $list = in_array($staff['gibbonPersonID'], $tripPeople) ? 'destination' : 'source';
        $array[$list][$staff['gibbonPersonID']] = Format::name($staff['title'], $staff['preferredName'], $staff['surname'], 'Staff', true, true);
        return $array;
    });

    //Student Data for Multi-Select
    $studentGateway = $container->get(StudentGateway::class);
    $studentCriteria = $studentGateway->newQueryCriteria()
        ->sortBy(['surname', 'preferredName']);

    $students = array_reduce($studentGateway->queryStudentsBySchoolYear($studentCriteria, $gibbonSchoolYearID)->toArray(), function ($array, $student) use ($tripPeople) {
        $list = in_array($student['gibbonPersonID'], $tripPeople) ? 'destination' : 'source';
        $array['students'][$list][$student['gibbonPersonID']] = Format::name($student['title'], $student['preferredName'], $student['surname'], 'Student', true) . ' - ' . $student['formGroup'].' ('.$student['yearGroup'].')'; 
        $array['form'][$student['gibbonPersonID']] = $student['formGroup'];
        $array['year'][$student['gibbonPersonID']] = $student['yearGroup'].'.'.$student['formGroup'];
        return $array;
    });

    //Submit Request Form
    $form = Form::create('requestForm', $session->get('absoluteURL') . '/modules/' . $moduleName . '/trips_submitRequestProcess.php');
    $form->addHiddenValue('address', $session->get('address'));
    $form->addHiddenValue('saveMode', 'Submit');
    $form->addMeta()->addDefaultContent('editProcess');

    $form->setTitle(__('Request'));

    //Basic Information Section
    $row = $form->addRow();
        $row->addHeading('Basic Information');

    $row = $form->addRow();
        $row->addLabel('title', 'Title');
        $row->addTextfield('title')
            ->setRequired(true);

    $row = $form->addRow();
        $col = $row->addColumn();
        $col->addLabel('description', 'Description');
        $col->addEditor("description", $guid)
            ->setRequired(true)
            ->showMedia(true)
            ->setRows(10);

    $row = $form->addRow();
        $row->addLabel('location', 'Location');
        $row->addTextfield('location')
            ->setRequired(true);

    //Date & Time Section
    $row = $form->addRow();
        $row->addHeading(__('Date & Time'));

    $dateTimeBlock = $form->getFactory()->createTable()->setClass('blank');
        $row = $dateTimeBlock->addRow();
            $row->addLabel('date', 'Start and End Dates for this block.')
                ->addClass('font-bold');

        $row = $dateTimeBlock->addRow();
                $row->addLabel('startDate', __('Start Date'));
                $row->addDate('startDate')
                    ->isRequired()
                    ->placeholder('Start Date');

                $row->addLabel('endDate', __('End Date'));
                $row->addDate('endDate')
                    ->isRequired()
                    ->placeholder('End Date');

        $dateTimeBlock->addRow()->addClass('h-2');

        $row = $dateTimeBlock->addRow();
            $row->addLabel('time', 'Start and End Times for each day. Leave blank if all day.')
                ->addClass('font-bold');

        $row = $dateTimeBlock->addRow();
            $row->addLabel('startTime', __('Start Time'));
            $row->addTime('startTime')
                ->placeholder('Start Time');

            $row->addLabel('endTime', __('End Time'));
            $row->addTime('endTime')
                ->placeholder('End Time');

    $addDateTimeBlockButton = $form->getFactory()->createButton(__('Add Date & Time'))->addClass('addBlock');

    //TODO: Require atleast one entry
    //TODO: Date/Time overlap checking? Is that even possible???
    $row = $form->addRow();
        $dateBlocks = $row->addCustomBlocks('dateTime', $session)
            ->fromTemplate($dateTimeBlock)
            ->settings([
                'placeholder' => __('Date/Time Blocks will appear here...'),
                'sortable' => true,
                'orderName' => 'dateTimeOrder'
            ])
            ->addToolInput($addDateTimeBlockButton);

    //Costs Section
    $row = $form->addRow();
        $row->addHeading(__('Costs'));

    //Block template
    $costBlock = $form->getFactory()->createTable()->setClass('blank');
        $row = $costBlock->addRow();
            $row->addLabel('title', __('Cost Name'));
            $row->addTextfield('title')
                ->isRequired()
                ->addClass('floatLeft');
        
            $row->addLabel('cost', __('Value'));
            $row->addCurrency('cost')
                ->isRequired()
                ->addClass('floatNone')
                ->minimum(0);

        $row = $costBlock->addRow()->addClass('showHide w-full');
            $col = $row->addColumn();
                $col->addTextArea('description')
                    ->setRows(2)
                    ->setClass('w-full floatNone')
                    ->placeholder(__('Cost Description'));

    //Tool Button
    $addBlockButton = $form->getFactory()
        ->createButton(__("Add Cost Block"))
        ->addClass('addBlock');

    //Custom Blocks
    $row = $form->addRow();
        $costBlocks = $row->addCustomBlocks("cost", $session)
            ->fromTemplate($costBlock)
            ->settings([
                'placeholder' => __('Cost Blocks will appear here...'),
                'sortable' => true,
                'orderName' => 'costOrder'
            ])
            ->addBlockButton('showHide', 'Show/Hide', 'plus.png')
            ->addToolInput($addBlockButton);

    //Risk Assessment and Letter to Parents
    $row = $form->addRow();
        $row->addHeading(__('Risk Assessment & Communication'));

    $row = $form->addRow();
        $row->addLabel('riskAssessmentTemplates', __('Risk Assessment Templates'));
        $row->addSelect('riskAssessmentTemplates')
            ->fromArray($templateNames)
            ->selected($defaultRiskTemplate);

    $row = $form->addRow();
        $col = $row->addColumn();
            $col->addLabel('riskAssessment', __('Risk Assessment'));
            $col->addEditor('riskAssessment', $guid)
                ->setRequired(true)
                ->showMedia(true)
                ->setRows(25)
                ->setValue($templates[$defaultRiskTemplate]);

    $letterToParentsTemplate = $settingGateway->getSettingByScope('Trip Planner', 'letterToParentsTemplate');
    $row = $form->addRow();
        $col = $row->addColumn();
            $col->addLabel('letterToParents', __('Letter to Parents'));
            $col->addEditor('letterToParents', $guid)
                ->showMedia(true)
                ->setRows(25)
                ->setValue($letterToParentsTemplate);

    //Participants section
    $row = $form->addRow();
        $row->addHeading(__('Participants'));

    if (!empty($trip['deepLearningExperienceID'])) {
        $form->toggleVisibilityByClass('deepLearningSyncY')->onCheckbox('deepLearningSync')->when('Y');
        $form->toggleVisibilityByClass('deepLearningSyncN')->onCheckbox('deepLearningSync')->whenNot('Y');

        $col = $form->addRow()->addColumn();
            $col->addContent(Format::alert(__m('This trip is synced with a Deep Learning experience. All participants should be edited in the experience rather than the trip itself.'), 'message'))->addClass('deepLearningSyncY');
            $col->addContent(Format::alert(__m('This trip will not be kept in sync with its Deep Learning experience. All participants should be managed manually below.'), 'warning'))->addClass('deepLearningSyncN');

        $col->addCheckbox('deepLearningSync')
            ->description(__m('Keep Deep Learning and Trip Planner participants in sync?'))
            ->setValue('Y')
            ->checked($trip['deepLearningSync'])
            ->alignRight();
    }

    $row = $form->addRow();
        $col = $row->addColumn();
            $col->addLabel('teachers', __('Teachers'));

            $multiSelect = $col->addMultiSelect('teachers')
                ->isRequired();

            $multiSelect->source()->fromArray($teachers['source'] ?? []);
            $multiSelect->destination()->fromArray($teachers['destination'] ?? []);

    $row = $form->addRow();
        $col = $row->addColumn();
            $col->addLabel('students', __('Students'));

            $multiSelect = $col->addMultiSelect('students')
                ->addSortableAttribute('Form', $students['form'])
                ->addSortableAttribute('Year', $students['year']);

            $multiSelect->source()->fromArray($students['students']['source'] ?? []);
            $multiSelect->destination()->fromArray($students['students']['destination'] ?? []);

    $row = $form->addRow();
        $row->addLabel('addByGroup', __('Add by Group'))
            ->description(__('Add or remove students to trip by Class, Activity or Messenger Group.'));

        $col = $row->addColumn()
            ->addClass('right flex-wrap');

            $col->addSelect('addStudentsByGroup')
                ->fromArray($addGroups)
                ->placeholder('None')
                ->setClass('w-full');

            $col->addButton(__('Add'), 'addGroup("Add")')
                ->addClass('flex-1 w-full mr-1');

            $col->addButton(__('Remove'), 'addGroup("Remove")')
                ->addClass('flex-1 w-full');

    if (!empty($trip['messengerGroupID'])) {
        $url = Url::fromModuleRoute('Messenger', 'groups_manage_edit')->withQueryParams(['gibbonGroupID' => $trip['messengerGroupID']]);
        $row = $form->addRow();
        $row->addLabel('messengerGroup', __m('Messenger Group'))->description(__m('This messenger group was created automatically when the Trip Request was created.'));
        $row->addContent(Format::link($url, __m('View Group')));
    } else {
        $row = $form->addRow();
            $row->addLabel('createGroup', __m('Create Messenger Group?'));
            $row->addYesNo('createGroup')
                ->selected($edit ? 'N' : 'Y');
    }
    
    if ($edit) {
        //Add parameters for editing
        $form->addHiddenValue('mode', 'edit');
        $form->addHiddenValue('tripPlannerRequestID', $tripPlannerRequestID);
        
        //Add view Header
        $form->addHeaderAction('view', __('View'))
            ->setURL('/modules/' . $moduleName . '/trips_requestView.php')
            ->addParam('tripPlannerRequestID', $tripPlannerRequestID)
            ->displayLabel();

        if (!empty($trip['deepLearningExperienceID'])) {
            $form->addHeaderAction('edit', __m('Edit DL Experience'))
                ->setURL('/modules/Deep Learning/experience_manage_edit.php')
                ->addParam('deepLearningExperienceID', $trip['deepLearningExperienceID'])
                ->displayLabel()
                ->prepend('&nbsp;&nbsp;|&nbsp;&nbsp;');
        }

        //Load values into form
        $form->loadAllValuesFrom($trip);

        //Get Trip Cost Data and add to CostBlocks
        $tripCostGateway = $container->get(TripCostGateway::class);
        $costCriteria = $tripCostGateway->newQueryCriteria()
            ->filterBy('tripPlannerRequestID', $tripPlannerRequestID)
            ->sortBy(['tripPlannerCostBreakdownID']);

        $costs = $tripCostGateway->queryTripCost($costCriteria);
        foreach ($costs as $cost) {
            $costBlocks->addBlock($cost['tripPlannerCostBreakdownID'], $cost);
        }

        //Get Trip Date Data and Add to DateBlocks
        $tripDayGateway = $container->get(TripDayGateway::class);
        $dayCriteria = $tripDayGateway->newQueryCriteria()
            ->filterBy('tripPlannerRequestID', $tripPlannerRequestID)
            ->sortBy(['tripPlannerRequestDaysID']);

        $days = $tripDayGateway->queryTripDay($dayCriteria);
        foreach ($days as $day) {

            if (boolval($day['allDay']) || empty($day['startTime']) || empty($day['endTime'])) {
                unset($day['startTime']);
                unset($day['endTime']);
            } else {
                $day['startTime'] = Format::time($day['startTime']);
                $day['endTime'] = Format::time($day['endTime']);
            }
            
            $dateBlocks->addBlock($day['tripPlannerRequestDaysID'], $day);
        }
    }

    if ($edit && !$isDraft) {
        $form->addRow()->addHeading(__m('Log'));
        $col = $form->addRow()->addColumn();
            $col->addLabel('changeSummary', __m('Change Summary'))->description(__m('Please briefly describe the changes you\'ve made to this trip. This summary will be added to the trip log.'));
            $col->addTextarea('changeSummary')->setRows(2)->required();
    }

    $row = $form->addRow('stickySubmit');
    if (!$edit || $isDraft) {
        $row->addButton(__('Save Draft'))
            ->setAttribute('@click', 'checkDraft(); $validate.updateData("form"); if ($validate.isComplete("requestForm")) { saveDraft() } else { invalid = true; }');
    }
    $row->addSubmit();

    print $form->getOutput();

    ?>
    <script>
        //Once date and time chaining is fixed in the core, most of this can go.
        var time = 'input[id*="Time"]';

        //Fix for datepicker in custom blocks
        $(document).on('click', '.addBlock', function () {
            $(time).removeClass('hasTimepicker').timepicker({'timeFormat': 'H:i', onSelect: function(){$(this).blur();}, onClose: function(){$(this).change();} });
        });

        function setTimepicker(input) {
            input.removeClass('hasTimepicker').timepicker({
                    'scrollDefault': 'now',
                    'timeFormat': 'H:i',
                    'minTime': '00:00',
                    'maxTime': '23:59',
                    onSelect: function(){$(this).blur();},
                    onClose: function(){$(this).change();}
                });
        }

        $(document).ready(function(){
            //This is to ensure that loaded blocks have timepickers
            $(time).each(function() {
                setTimepicker($(this));
            });

            // Ensure that participants cannot get out of sync with the deep learning experience
            <?php if (!empty($trip['deepLearningExperienceID']) && $trip['deepLearningSync'] == 'Y') { ?>
                $('#teachers').attr('readonly', true);
                $('#teachersSource').attr('readonly', true);
                $('#students').attr('readonly', true);
                $('#studentsSource').attr('readonly', true);
                $('#addStudentsByGroup').attr('disabled', true);
            <?php } ?>
        });

        $(document).on('change', '#deepLearningSync', function() {
            var sync = $(this).is(':checked') ? true : false;
            $('#teachers').attr('readonly', sync);
            $('#teachersSource').attr('readonly', sync);
            $('#students').attr('readonly', sync);
            $('#studentsSource').attr('readonly', sync);
            $('#addStudentsByGroup').attr('disabled', sync);
        });

        //Javascript to change risk assessment when template selector is changed.
        <?php echo 'var templates = ' . json_encode($templates) . ';'; ?>
        $("select[name=riskAssessmentTemplates]").on('change', function(){
            var templateID = $(this).val();
            if (templateID != '' && templateID >= 0) {
                if(confirm('Are you sure you want to use this template. Warning: This will overwrite any thing currently written.')) {
                    tinyMCE.get('riskAssessment').setContent(templates[templateID]);
                }
            }
        });

        //function to add a group to the students list.
        function addGroup(mode) {
            var data = $('#addStudentsByGroup').val();
            if(data != 'None') {
                $('#addGroupDiv').load("<?php print $session->get('absoluteURL') . '/modules/' . rawurlencode($moduleName) . '/trips_submitRequestAddGroupAjax.php'?>", 'data=' + data + '&mode=' + mode);
            }
        }

        function checkDraft() {
            $('option', '#teachers').each(function() {
                $(this).prop('selected', true);
            });
            $('option', '#students').each(function() {
                $(this).prop('selected', true);
            });

            document.querySelectorAll('*[data-error-msg]').forEach(function (element) {
                element.dispatchEvent(new Event('change'));
                element.dispatchEvent(new Event('blur'));
            });
        }

        function saveDraft() {
            window.onbeforeunload = null;

            $('input[name="saveMode"]').val('Draft');
            document.getElementById('requestForm').submit();
        }
    </script>

    <div id="addGroupDiv"></div>
    <?php
}
?>
