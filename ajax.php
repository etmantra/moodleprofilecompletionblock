<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

require_login();
header('Content-Type: application/json; charset=utf-8');

try {
    require_sesskey();

    $action = optional_param('action', 'savefield', PARAM_ALPHA);

    // ── Interest tag actions ──────────────────────────────────────────
    if ($action === 'addinterest') {
        $tag = required_param('tag', PARAM_NOTAGS);
        $tag = trim($tag);
        if ($tag === '') {
            echo json_encode(['success' => false, 'error' => 'Tag cannot be empty.']);
            exit;
        }
        $existing = core_tag_tag::get_item_tags('core', 'user', $USER->id);
        $names    = array_map(function($t) { return $t->rawname; }, $existing);
        if (!in_array($tag, $names)) {
            $names[] = $tag;
            core_tag_tag::set_item_tags('core', 'user', $USER->id,
                context_user::instance($USER->id), $names);
        }
        $updated = core_tag_tag::get_item_tags('core', 'user', $USER->id);
        $tags    = array_map(function($t) {
            return ['id' => (int)$t->id, 'name' => $t->name];
        }, $updated);
        echo json_encode(['success' => true, 'tags' => array_values($tags)]);
        exit;

    } elseif ($action === 'removeinterest') {
        $taginstanceid = required_param('taginstanceid', PARAM_INT);
        $DB->delete_records('tag_instance', [
            'id'        => $taginstanceid,
            'itemid'    => $USER->id,
            'itemtype'  => 'user',
            'component' => 'core',
        ]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ── Save profile field (default action) ──────────────────────────
    $fieldkey = required_param('field', PARAM_ALPHANUMEXT);
    $value    = optional_param('value', '', PARAM_RAW_TRIMMED);

    $standard_allowed = ['description', 'country', 'city', 'institution', 'department'];

    if (in_array($fieldkey, $standard_allowed)) {
        $update               = new stdClass();
        $update->id           = $USER->id;
        $update->$fieldkey    = $value;
        $update->timemodified = time();
        $DB->update_record('user', $update);
        $USER->$fieldkey = $value;

    } else {
        // Accept any field the admin has created in user_info_field.
        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => $fieldkey]);
        if (!$fieldid) {
            echo json_encode(['success' => false, 'error' => 'Invalid field.']);
            exit;
        }
        $existing = $DB->get_record('user_info_data', ['userid' => $USER->id, 'fieldid' => $fieldid]);
        if ($existing) {
            $DB->update_record('user_info_data', (object)[
                'id'         => $existing->id,
                'data'       => $value,
                'dataformat' => 0,
            ]);
        } else {
            $DB->insert_record('user_info_data', (object)[
                'userid'     => $USER->id,
                'fieldid'    => $fieldid,
                'data'       => $value,
                'dataformat' => 0,
            ]);
        }
    }

    // Recalculate completion (mirrors block logic exactly).
    // Re-read standard fields from DB so the just-saved value is included.
    $dbuser = $DB->get_record('user', ['id' => $USER->id],
                'id, picture, description, country, city, institution, department');

    $checks = [
        !empty($dbuser->picture),
        !empty($dbuser->description),
        !empty($dbuser->country),
        !empty($dbuser->city),
        !empty($dbuser->institution),
        !empty($dbuser->department),
    ];

    // Query custom field values directly (bypasses profile_user_record filtering).
    $custom_keys     = ['jobtitle', 'industry', 'experience_level', 'learning_goals', 'current_skills', 'interests'];
    $existing_custom = $DB->get_fieldset_select('user_info_field', 'shortname', '1=1');
    $existing_custom = array_flip($existing_custom ?? []);

    if (!empty($existing_custom)) {
        $shortnames = array_keys($existing_custom);
        list($insql, $inparams) = $DB->get_in_or_equal($shortnames, SQL_PARAMS_NAMED);
        $sql = "SELECT f.shortname, COALESCE(d.data, '') AS data
                  FROM {user_info_field} f
             LEFT JOIN {user_info_data} d ON d.fieldid = f.id AND d.userid = :userid
                 WHERE f.shortname $insql";
        $inparams['userid'] = $USER->id;
        $custom_vals = [];
        foreach ($DB->get_records_sql($sql, $inparams) as $row) {
            $custom_vals[$row->shortname] = $row->data;
        }
        foreach ($custom_keys as $ck) {
            if (!isset($existing_custom[$ck])) {
                continue;
            }
            $checks[] = !empty($custom_vals[$ck]);
        }
    }

    $total = count($checks);
    $done  = array_sum(array_map('intval', $checks));
    $pct   = $total > 0 ? (int) round($done / $total * 100) : 0;

    echo json_encode(['success' => true, 'pct' => $pct, 'done' => $done, 'total' => $total]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
