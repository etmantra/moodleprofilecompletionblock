<?php
defined('MOODLE_INTERNAL') || die();

class block_profile_completion extends block_base {

    public function init() {
        $this->title = get_string('pluginname', 'block_profile_completion');
    }

    public function applicable_formats() {
        return ['all' => true];
    }

    public function instance_allow_multiple() {
        return false;
    }

    public function get_content() {
        global $CFG, $USER, $OUTPUT, $DB;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content         = new stdClass();
        $this->content->footer = '';

        if (!isloggedin() || isguestuser()) {
            $this->content->text = '';
            return $this->content;
        }

        require_once($CFG->dirroot . '/user/profile/lib.php');

        // Read standard fields fresh from DB (AJAX_SCRIPT closes the session early
        // so the $USER session object stays stale after inline saves).
        $dbuser    = $DB->get_record('user', ['id' => $USER->id],
                        'id, picture, description, country, city, institution, department');
        $editurl   = (new moodle_url('/user/edit.php', ['id' => $USER->id]))->out(false);
        $ajaxurl   = (new moodle_url('/blocks/profile_completion/ajax.php'))->out(false);
        $countries = get_string_manager()->get_list_of_countries(true);
        $sesskey   = sesskey();

        // Load user's interests (Moodle native tag system — not a completion field).
        $interests = core_tag_tag::get_item_tags('core', 'user', $USER->id);

        // Single query: all custom profile fields + this user's values.
        // Dynamic discovery — automatically includes any field admin creates.
        $sql = "SELECT f.id, f.shortname, f.name, f.datatype, f.param1,
                       COALESCE(d.data, '') AS data
                  FROM {user_info_field} f
             LEFT JOIN {user_info_data} d ON d.fieldid = f.id AND d.userid = :userid
                 ORDER BY f.categoryid, f.sortorder";
        $custom_rows = $DB->get_records_sql($sql, ['userid' => $USER->id]);

        // ----------------------------------------------------------------
        // Field definitions — standard (hardcoded) + custom (dynamic)
        // ----------------------------------------------------------------
        $fields = [
            [
                'key'     => 'picture',
                'label'   => get_string('field_picture', 'block_profile_completion'),
                'type'    => 'photo',
                'value'   => !empty($dbuser->picture),
                'current' => '',
                'options' => [],
            ],
            [
                'key'     => 'description',
                'label'   => get_string('field_description', 'block_profile_completion'),
                'type'    => 'textarea',
                'value'   => !empty($dbuser->description),
                'current' => $dbuser->description ?? '',
                'options' => [],
            ],
            [
                'key'     => 'country',
                'label'   => get_string('field_country', 'block_profile_completion'),
                'type'    => 'country',
                'value'   => !empty($dbuser->country),
                'current' => $dbuser->country ?? '',
                'options' => [],
            ],
            [
                'key'     => 'city',
                'label'   => get_string('field_city', 'block_profile_completion'),
                'type'    => 'text',
                'value'   => !empty($dbuser->city),
                'current' => $dbuser->city ?? '',
                'options' => [],
            ],
            [
                'key'     => 'institution',
                'label'   => get_string('field_institution', 'block_profile_completion'),
                'type'    => 'text',
                'value'   => !empty($dbuser->institution),
                'current' => $dbuser->institution ?? '',
                'options' => [],
            ],
            [
                'key'     => 'department',
                'label'   => get_string('field_department', 'block_profile_completion'),
                'type'    => 'text',
                'value'   => !empty($dbuser->department),
                'current' => $dbuser->department ?? '',
                'options' => [],
            ],
        ];

        // Dynamically append every custom profile field the admin has created.
        $custom_field_vals = [];
        foreach ($custom_rows as $row) {
            $custom_field_vals[$row->shortname] = $row->data;

            if ($row->datatype === 'textarea') {
                $input_type = 'textarea';
            } elseif ($row->datatype === 'menu') {
                $input_type = 'select';
            } else {
                $input_type = 'text'; // text, url, etc.
            }

            $options = [];
            if ($input_type === 'select' && !empty($row->param1)) {
                $options = array_values(array_filter(array_map('trim', explode("\n", $row->param1))));
            }

            $fields[] = [
                'key'     => $row->shortname,
                'label'   => $row->name,  // admin-defined display name
                'type'    => $input_type,
                'value'   => $row->data !== '',
                'current' => $row->data,
                'options' => $options,
            ];
        }

        // ----------------------------------------------------------------
        // Calculate completion
        // ----------------------------------------------------------------
        $total   = count($fields);
        $done    = 0;
        $missing = [];
        foreach ($fields as $f) {
            if ($f['value']) {
                $done++;
            } else {
                $missing[] = $f;
            }
        }
        $pct = $total > 0 ? (int) round($done / $total * 100) : 0;

        // ----------------------------------------------------------------
        // Hero header
        // ----------------------------------------------------------------
        $avatar   = $OUTPUT->user_picture($USER, ['size' => 72, 'class' => 'pcb-avatar']);
        $fullname = s(fullname($USER));

        $subtitle_parts = [];
        if (!empty($custom_field_vals['jobtitle'])) {
            $subtitle_parts[] = s($custom_field_vals['jobtitle']);
        }
        if (!empty($dbuser->institution)) {
            $subtitle_parts[] = s($dbuser->institution);
        }
        $subtitle = implode(' &middot; ', $subtitle_parts);

        $location_parts = [];
        if (!empty($dbuser->city)) {
            $location_parts[] = s($dbuser->city);
        }
        if (!empty($dbuser->country) && isset($countries[$dbuser->country])) {
            $location_parts[] = s($countries[$dbuser->country]);
        }
        $location = implode(', ', $location_parts);

        $html  = '<div class="pcb-wrap">';
        $html .= '<div class="pcb-hero">';
        $html .= '<div class="pcb-avatar-wrap">' . $avatar . '</div>';
        $html .= '<div class="pcb-hero-info">';
        $html .= '<div class="pcb-fullname">' . $fullname . '</div>';

        if ($subtitle) {
            $html .= '<div class="pcb-subtitle">' . $subtitle . '</div>';
        } else {
            $html .= '<div class="pcb-subtitle pcb-placeholder">';
            $html .= get_string('addjob', 'block_profile_completion');
            $html .= '</div>';
        }

        if ($location) {
            $html .= '<div class="pcb-location">' . $location . '</div>';
        }

        $html .= '<a class="pcb-editlink" href="' . $editurl . '">';
        $html .= get_string('editprofile', 'block_profile_completion');
        $html .= '</a>';
        $html .= '</div>'; // .pcb-hero-info
        $html .= '</div>'; // .pcb-hero

        // ----------------------------------------------------------------
        // Completion bar
        // ----------------------------------------------------------------
        $html .= '<div class="pcb-completion">';
        $html .= '<div class="pcb-completion-header">';
        $html .= '<span class="pcb-completion-label">' . get_string('pluginname', 'block_profile_completion') . '</span>';
        $html .= '<span class="pcb-pct-label">' . get_string('completionpct', 'block_profile_completion', $pct) . '</span>';
        $html .= '</div>';
        $html .= '<div class="pcb-bar-track">';
        $html .= '<div class="pcb-bar-fill" style="width:' . $pct . '%"></div>';
        $html .= '</div>';

        if ($pct < 100) {
            $html .= '<p class="pcb-prompt">' . get_string('completionprompt', 'block_profile_completion') . '</p>';
        } else {
            $html .= '<p class="pcb-prompt pcb-complete">' . get_string('completioncomplete', 'block_profile_completion') . '</p>';
        }
        $html .= '</div>'; // .pcb-completion

        // ----------------------------------------------------------------
        // Interests section (optional — never counts toward completion)
        // ----------------------------------------------------------------
        $html .= '<div class="pcb-interests">';
        $html .= '<span class="pcb-interests-label">Interests</span>';
        $html .= '<div class="pcb-interests-pills" id="pcb-interests-pills">';
        foreach ($interests as $tag) {
            $html .= '<span class="pcb-interest-pill" data-taginstanceid="' . (int)$tag->id . '">';
            $html .= s($tag->name);
            $html .= '<button type="button" class="pcb-interest-remove" aria-label="Remove">&times;</button>';
            $html .= '</span>';
        }
        $html .= '</div>'; // .pcb-interests-pills
        $html .= '<div class="pcb-interest-add-wrap">';
        $html .= '<button type="button" class="pcb-interest-add-btn" id="pcb-interest-add-btn">+ Add interest</button>';
        $html .= '<div class="pcb-interest-input-row" id="pcb-interest-input-row" style="display:none">';
        $html .= '<input type="text" class="form-control form-control-sm" id="pcb-interest-input" placeholder="e.g. Machine Learning">';
        $html .= '<button type="button" class="btn btn-primary btn-sm" id="pcb-interest-save">Add</button>';
        $html .= '<button type="button" class="btn btn-secondary btn-sm" id="pcb-interest-cancel">Cancel</button>';
        $html .= '</div>';
        $html .= '</div>'; // .pcb-interest-add-wrap
        $html .= '</div>'; // .pcb-interests

        // ----------------------------------------------------------------
        // Missing fields list (max 4 shown, rest in overflow)
        // ----------------------------------------------------------------
        if (!empty($missing)) {
            $max_shown = 4;
            $shown     = array_slice($missing, 0, $max_shown);
            $overflow  = count($missing) - count($shown);

            $html .= '<div class="pcb-missing">';
            $html .= '<div class="pcb-missing-label">' . get_string('missingfields', 'block_profile_completion') . '</div>';
            $html .= '<div class="pcb-chips">';

            foreach ($shown as $field) {
                $label        = $field['label'];
                $options_json = htmlspecialchars(json_encode($field['options']), ENT_QUOTES, 'UTF-8');
                $current_esc  = htmlspecialchars($field['current'], ENT_QUOTES, 'UTF-8');
                $label_esc    = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

                $html .= '<button type="button" class="pcb-chip"';
                $html .= ' data-field="' . $field['key'] . '"';
                $html .= ' data-type="' . $field['type'] . '"';
                $html .= ' data-label="' . $label_esc . '"';
                $html .= ' data-current="' . $current_esc . '"';
                $html .= ' data-options="' . $options_json . '">';
                $html .= '<span class="pcb-chip-label">' . s($label) . '</span>';
                $html .= '<span class="pcb-chip-add">' . get_string('addfield', 'block_profile_completion') . ' &rarr;</span>';
                $html .= '</button>';
            }

            $html .= '</div>'; // .pcb-chips

            if ($overflow > 0) {
                $html .= '<span class="pcb-more" data-overflow="' . $overflow . '">';
                $html .= get_string('andmore', 'block_profile_completion', $overflow);
                $html .= '</span>';
            }

            $html .= '<div class="pcb-cta-wrap">';
            $html .= '<a class="pcb-cta btn btn-primary" href="' . $editurl . '">';
            $html .= get_string('completeprofile', 'block_profile_completion');
            $html .= '</a>';
            $html .= '</div>';

            $html .= '</div>'; // .pcb-missing
        }

        // ----------------------------------------------------------------
        // Modal (Bootstrap 5 — Moodle 4.x)
        // ----------------------------------------------------------------
        $html .= '
<div class="modal fade" id="pcb-field-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="pcb-modal-heading"></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="pcb-modal-field-wrap"></div>
        <div id="pcb-modal-error" class="alert alert-danger mt-2 py-2" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="pcb-modal-save">Save</button>
      </div>
    </div>
  </div>
</div>';

        $html .= '</div>'; // .pcb-wrap

        // ----------------------------------------------------------------
        // Inline JavaScript
        // ----------------------------------------------------------------
        $js_config = json_encode([
            'ajaxUrl'  => $ajaxurl,
            'sesskey'  => $sesskey,
            'countries' => $countries,
            'editUrl'  => $editurl,
        ]);

        $html .= '<script>
(function() {
    var PCB = ' . $js_config . ';
    var currentField = null;

    // ── Modal helpers (no Bootstrap JS dependency) ────────────────────
    function showModal() {
        var el = document.getElementById("pcb-field-modal");
        if (!el) { return; }
        el.style.display = "block";
        el.removeAttribute("aria-hidden");
        setTimeout(function() { el.classList.add("show"); }, 10);
        document.body.classList.add("modal-open");
        var bd = document.createElement("div");
        bd.className = "modal-backdrop fade show";
        bd.id = "pcb-modal-backdrop";
        document.body.appendChild(bd);
    }

    function hideModal() {
        var el = document.getElementById("pcb-field-modal");
        if (!el) { return; }
        el.classList.remove("show");
        el.setAttribute("aria-hidden", "true");
        setTimeout(function() { el.style.display = "none"; }, 200);
        document.body.classList.remove("modal-open");
        var bd = document.getElementById("pcb-modal-backdrop");
        if (bd) { bd.remove(); }
    }

    // ── Field builder ─────────────────────────────────────────────────
    function buildInput(type, current, options) {
        var wrap = document.getElementById("pcb-modal-field-wrap");
        wrap.innerHTML = "";
        var el;

        if (type === "text") {
            el = document.createElement("input");
            el.type = "text";
            el.className = "form-control";
            el.id = "pcb-field-input";
            el.value = current || "";

        } else if (type === "textarea") {
            el = document.createElement("textarea");
            el.className = "form-control";
            el.id = "pcb-field-input";
            el.rows = 4;
            el.value = current || "";

        } else if (type === "select") {
            el = document.createElement("select");
            el.className = "form-select";
            el.id = "pcb-field-input";
            var blank = document.createElement("option");
            blank.value = "";
            blank.textContent = "\u2014 Select \u2014";
            el.appendChild(blank);
            (options || []).forEach(function(opt) {
                var o = document.createElement("option");
                o.value = opt;
                o.textContent = opt;
                if (opt === current) { o.selected = true; }
                el.appendChild(o);
            });

        } else if (type === "country") {
            el = document.createElement("select");
            el.className = "form-select";
            el.id = "pcb-field-input";
            var blank2 = document.createElement("option");
            blank2.value = "";
            blank2.textContent = "\u2014 Select country \u2014";
            el.appendChild(blank2);
            Object.entries(PCB.countries).forEach(function(pair) {
                var o = document.createElement("option");
                o.value = pair[0];
                o.textContent = pair[1];
                if (pair[0] === current) { o.selected = true; }
                el.appendChild(o);
            });
        }

        if (el) {
            wrap.appendChild(el);
            setTimeout(function() { el.focus(); }, 150);
        }
    }

    // ── UI update after save ──────────────────────────────────────────
    function updateUI(pct, savedField) {
        var fill = document.querySelector(".pcb-bar-fill");
        if (fill) { fill.style.width = pct + "%"; }
        var lbl = document.querySelector(".pcb-pct-label");
        if (lbl) { lbl.textContent = pct + "% complete"; }

        var chip = document.querySelector(".pcb-chip[data-field=\"" + savedField + "\"]");
        if (chip) { chip.remove(); }

        var overflowEl = document.querySelector(".pcb-more[data-overflow]");
        if (overflowEl) {
            var count = parseInt(overflowEl.dataset.overflow, 10) - 1;
            if (count <= 0) {
                overflowEl.remove();
                overflowEl = null;
            } else {
                overflowEl.dataset.overflow = count;
                overflowEl.textContent = "and " + count + " more...";
            }
        }

        var remainingChips = document.querySelectorAll(".pcb-chip").length;
        if (remainingChips === 0 && overflowEl) {
            location.reload();
            return;
        }

        if (pct >= 100) {
            var missingEl = document.querySelector(".pcb-missing");
            if (missingEl) { missingEl.remove(); }
            var prompt = document.querySelector(".pcb-prompt");
            if (prompt) {
                prompt.classList.add("pcb-complete");
                prompt.textContent = "Your profile is complete!";
            }
        }
    }

    // ── Chip click ────────────────────────────────────────────────────
    function onChipClick(chip) {
        if (chip.dataset.type === "photo") {
            window.location.href = PCB.editUrl;
            return;
        }

        currentField = chip.dataset.field;
        document.getElementById("pcb-modal-heading").textContent = "Add " + chip.dataset.label;
        document.getElementById("pcb-modal-error").style.display = "none";

        var opts = [];
        try { opts = JSON.parse(chip.dataset.options || "[]"); } catch (e) { }

        buildInput(chip.dataset.type, chip.dataset.current, opts);
        showModal();
    }

    // ── Interests ─────────────────────────────────────────────────────
    function initRemoveButtons() {
        document.querySelectorAll(".pcb-interest-remove").forEach(function(btn) {
            if (btn._pcbBound) { return; }
            btn._pcbBound = true;
            btn.addEventListener("click", function() {
                var pill = this.closest(".pcb-interest-pill");
                var id   = pill.dataset.taginstanceid;
                fetch(PCB.ajaxUrl, {
                    method:  "POST",
                    headers: {"Content-Type": "application/x-www-form-urlencoded"},
                    body:    "sesskey=" + encodeURIComponent(PCB.sesskey)
                           + "&action=removeinterest"
                           + "&taginstanceid=" + encodeURIComponent(id)
                })
                .then(function(r) { return r.json(); })
                .then(function(data) { if (data.success) { pill.remove(); } });
            });
        });
    }

    function initInterests() {
        initRemoveButtons();

        var addBtn   = document.getElementById("pcb-interest-add-btn");
        var inputRow = document.getElementById("pcb-interest-input-row");
        var input    = document.getElementById("pcb-interest-input");
        if (!addBtn) { return; }

        addBtn.addEventListener("click", function() {
            addBtn.style.display = "none";
            inputRow.style.display = "flex";
            input.focus();
        });

        document.getElementById("pcb-interest-cancel").addEventListener("click", function() {
            inputRow.style.display = "none";
            addBtn.style.display = "";
            input.value = "";
        });

        function doAdd() {
            var tag = input.value.trim();
            if (!tag) { return; }
            fetch(PCB.ajaxUrl, {
                method:  "POST",
                headers: {"Content-Type": "application/x-www-form-urlencoded"},
                body:    "sesskey=" + encodeURIComponent(PCB.sesskey)
                       + "&action=addinterest"
                       + "&tag=" + encodeURIComponent(tag)
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    var pills = document.getElementById("pcb-interests-pills");
                    pills.innerHTML = "";
                    data.tags.forEach(function(t) {
                        var pill = document.createElement("span");
                        pill.className = "pcb-interest-pill";
                        pill.dataset.taginstanceid = t.id;
                        pill.textContent = t.name;
                        var rm = document.createElement("button");
                        rm.type = "button";
                        rm.className = "pcb-interest-remove";
                        rm.setAttribute("aria-label", "Remove");
                        rm.innerHTML = "&times;";
                        pill.appendChild(rm);
                        pills.appendChild(pill);
                    });
                    initRemoveButtons();
                    input.value = "";
                    inputRow.style.display = "none";
                    addBtn.style.display = "";
                }
            });
        }

        document.getElementById("pcb-interest-save").addEventListener("click", doAdd);
        input.addEventListener("keydown", function(e) {
            if (e.key === "Enter") { doAdd(); }
        });
    }

    // ── Init ──────────────────────────────────────────────────────────
    function init() {
        initInterests();

        document.querySelectorAll(".pcb-chip").forEach(function(chip) {
            chip.addEventListener("click", function() { onChipClick(this); });
        });

        // Close buttons
        document.querySelectorAll("#pcb-field-modal [data-bs-dismiss=\"modal\"]").forEach(function(btn) {
            btn.addEventListener("click", hideModal);
        });

        // Click-outside-modal to close
        document.getElementById("pcb-field-modal").addEventListener("click", function(e) {
            if (e.target === this) { hideModal(); }
        });

        // Save button
        var saveBtn = document.getElementById("pcb-modal-save");
        if (!saveBtn) { return; }

        saveBtn.addEventListener("click", function() {
            var input = document.getElementById("pcb-field-input");
            var value = input ? input.value.trim() : "";
            var errEl = document.getElementById("pcb-modal-error");

            if (!value) {
                errEl.textContent = "Please enter a value before saving.";
                errEl.style.display = "block";
                return;
            }
            errEl.style.display = "none";
            saveBtn.disabled    = true;
            saveBtn.textContent = "Saving\u2026";

            fetch(PCB.ajaxUrl, {
                method:  "POST",
                headers: {"Content-Type": "application/x-www-form-urlencoded"},
                body:    "sesskey=" + encodeURIComponent(PCB.sesskey)
                       + "&field=" + encodeURIComponent(currentField)
                       + "&value=" + encodeURIComponent(value)
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    hideModal();
                    updateUI(data.pct, currentField);
                } else {
                    errEl.textContent = data.error || "An error occurred.";
                    errEl.style.display = "block";
                }
            })
            .catch(function() {
                errEl.textContent = "Network error. Please try again.";
                errEl.style.display = "block";
            })
            .finally(function() {
                saveBtn.disabled    = false;
                saveBtn.textContent = "Save";
            });
        });

        // Enter key submits single-line inputs
        document.getElementById("pcb-field-modal").addEventListener("keydown", function(e) {
            if (e.key === "Enter") {
                var input = document.getElementById("pcb-field-input");
                if (input && input.tagName !== "TEXTAREA") {
                    document.getElementById("pcb-modal-save").click();
                }
            }
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
</script>';

        $this->content->text = $html;
        return $this->content;
    }
}
