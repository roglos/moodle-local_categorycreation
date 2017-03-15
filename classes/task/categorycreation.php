<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * A scheduled task for scripted database integrations.
 *
 * @package    local_categorycreation
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_categorycreation\task;
use stdClass;

/**
 * A scheduled task for scripted database integrations.
 *
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class categorycreation extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('categorycreation', 'local_categorycreation');
    }

    /**
     * Run sync.
     */
    public function execute() {

        /* Category Creation script *
         * ======================== */

        global $CFG, $DB;
        require_once($CFG->libdir . "/coursecatlib.php");
        $leveltable = 'usr_data_categorylevels';
        $levelsql = 'SELECT * FROM ' . $leveltable . ' WHERE inuse = 1 ORDER BY rank ASC;';
        $levelparams = null;
        $levels = $DB->get_records_sql($levelsql, $levelparams);
        // $levels = array('FAC', 'SCH', 'SUB', 'DOM');
print_r($levels);
        /* Table name: This table should contain id:category name:category parent (in this case
         * using a unique idnumber as parent->id is not necessarily known):category idnumber (as
         * a unique identifier). */
        foreach ($levels as $l) {
            $level = $l->categorylevel;
            $table = 'usr_data_categories';
            $sql = 'SELECT * FROM ' . $table . ' WHERE category_idnumber LIKE "' . $level .'-%"';
            $params = null;
            // Read database table.
            $rs = $DB->get_records_sql($sql, $params);

            // Loop through all records found.
            foreach ($rs as $category) {
                $data = array();
                // Set all the $data
                // Error trap - category name is required and set $data['name'].
                if (isset($category->category_idnumber)) {
                    $data['idnumber'] = $category->category_idnumber;
                } else {
                    echo 'Category IdNumber required';
                    break;
                }

                // If no name is set, make name = idnumber.
                if (isset($category->category_name) && $category->category_name !== 'Undefined') {
                    $data['name'] = $category->category_name;
                } else {
                    $data['name'] = $category->category_idnumber;
                }

                // Default $parent values as Top category, so if none set new category defaults to top level.
                $parent = $DB->get_record('course_categories', array('id' => 1));
                $parent->id = 0;
                $parent->visible = 1;
                $parent->depth = 0;
                $parent->path = '';
                // Set $data['parent'] by fetching parent->id based on unique parent category idnumber, if set.
                if (!$category->parent_cat_idnumber == '') {
                    // Check if the parent category already exists - based on unique idnumber.
                    if ($DB->record_exists('course_categories',
                        array('idnumber' => $category->parent_cat_idnumber))) {
                        // Fetch that parent category details.
                        $parent = $DB->get_record('course_categories',
                            array('idnumber' => $category->parent_cat_idnumber));
                    }
                }
                // Set $data['parent'] as the id of the parent category and depth as parent +1.
                $data['parent'] = $parent->id;
                $data['depth'] = $parent->depth + 1;

                // Set $data['description'] But this is always empty in our case as it is not being stored.
                $data['description'] = '';
                $data['descriptionformat'] = FORMAT_MOODLE;

                // Create a category that inherits visibility from parent.
                $data['visible'] = $parent->visible;
                // If a category is marked as 'deleted' then ensure it is hidden - don't actually delete it.
                if ($category->deleted) {
                    $data['visible'] = 0;
                }
                // In case parent is hidden, when it changes visibility this new subcategory will automatically become visible too.
                $data['visibleold'] = 1;
                // Set default sort order as 0 and time modified as now.
                $data['sortorder'] = 0;
                $data['timemodified'] = time();

                if (!$DB->record_exists('course_categories',
                    array('idnumber' => $category->category_idnumber))) {
                    // Set new category id by inserting the data created above.
                    $data['id'] = $DB->insert_record('course_categories', $data);
                    // Update path (only possible after we know the category id).
                    $path = $parent->path . '/' . $data['id'];
                    $DB->set_field('course_categories', 'path', $path, array('id' => $data['id']));

                } else {
                    // IF category already exists, fetch the existing id.
                    $data['id'] = $DB->get_field('course_categories', 'id', array('idnumber' => $category->category_idnumber));
                    // Set the path as necessary.
                    $data['path'] = $parent->path . '/' . $data['id'];
                    // As category already exists, update it with any changes.
                    $DB->update_record('course_categories', $data);
                }

                // Create cohort for this category.
                    $cohort_data = array();
                    $cohort_data['contextid'] = 1; // Check context for SITE - on LIVE it will be 3
                    $cohort_data['name'] = $category->category_name;
                    $cohort_data['idnumber'] = $category->category_idnumber;
                    $cohort_data['description'] = '';
                    $cohort_data['descriptionformat'] = '';
                    $cohort_data['visible'] = 1;
                    $cohort_data['component'] = '';
                    $cohort_data['timecreated'] = time();
                    $cohort_data['timemodified'] = 0;

                if (!$DB->record_exists('cohort',array('idnumber' => $category->category_idnumber))) {

                    $DB->insert_record('cohort', $cohort_data);
                } else {
                    $cohort_data['id'] = $DB->get_field('cohort', 'id', array('idnumber' => $category->category_idnumber));
                    $DB->update_record('cohort', $cohort_data);
                }

                // Use core function to adjust sort order as appropriate.
                fix_course_sortorder();

                // Set values to write to mdl_context table.
                $record['contextlevel'] = 40;
                $record['instanceid']   = $data['id'];
                $record['depth']        = 1; // Set as default.
                $record['path']         = null; // Not known before insert.
                $parentpath = '/1';
                $parentdepth = 1;
                // Adjust values as approriate to account for parent context - if there is one.
                if ($data['parent'] != 0) {
                    // SQL to find parent context.
                    $sql = "SELECT * FROM mdl_context
                        WHERE `contextlevel` = 40 AND `instanceid` = ".$data['parent'];
                    $params = null;
                    $parentcontext = $DB->get_record_sql($sql, $params);
                    $parentpath = $parentcontext->path;
                    $parentdepth = $parentcontext->depth;
                }

                // Write default data and find record id if record doesn't already exist.
                if (!$DB->record_exists('context',
                    array('contextlevel' => 40, 'instanceid' => $record['instanceid']))) {
                        $record['id'] = $DB->insert_record('context', $record);
                } else {
                    $record['id'] = $DB->get_field('context', 'id',
                    array('contextlevel' => 40, 'instanceid' => $record['instanceid']));
                }
                // Adjust values for parent contexts now we have record id.
                $record['path'] = $parentpath.'/'.$record['id'];
                $record['depth'] = $parentdepth + 1;
                $DB->update_record('context', $record);

                        /* TO-DO: Add code to check courses are in the correct category -
                        * Courses don't have path or context_path in table, so simply adjusting the category should be fine.
                        *
                        * ASSUME - courses already exist in a category, that category is determined in the mdl_course table by mdl_course.category
                        * If category details change, irrelevant, mdl_course.category  wont change
                        * If course changes category then usr_data_object_categories.category_idnumber will change, but usr_ro_modules takes all
                        * 'course' creation sources into a single table with mdl_course_categories.id already added:
                        * THIS CODE SHOULD GO THERE!!!
                        *
                        */
            }
        }
        // Context maintenance stuff.
        \context_helper::cleanup_instances();
        mtrace(' Cleaned up context instances');
        \context_helper::build_all_paths(false);
        // If you suspect that the context paths are somehow corrupt
        // replace the line below with: context_helper::build_all_paths(true).

        // Add category.id to source data table based on category.idnumber for auto creation of overarching course pages.
        // To be done in course creation table, incorporating sandboxes etc.
//        $sourcetable = 'usr_data_courses';
//        $sql = 'UPDATE ' . $sourcetable . '
//            INNER JOIN mdl_course_categories ON '.$sourcetable.'.category_idnumber = mdl_course_categories.idnumber
//            SET category_id = mdl_course_categories.id';
//        $DB->execute($sql);


    }
}
