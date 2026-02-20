<!DOCTYPE html>
<html lang="en">

<head>
    <?php
    include 'common/htmlMeta.inc';
    require_once "common.php";
    require_once 'config.php';
    include 'common/menuHead.inc';
    ?>

    <title><? echo $pageTitle; ?> - Input Mixing</title>

    <?php $modalMode = isset($_GET['modal']) && $_GET['modal'] == '1'; ?>

    <style>
        .ig-card {
            border: 1px solid var(--bs-border-color, #dee2e6);
            border-radius: 8px;
            margin-bottom: 1.5rem;
            background: var(--bs-body-bg, #fff);
        }

        .ig-card.disabled-group {
            opacity: 0.6;
        }

        .ig-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--bs-border-color, #dee2e6);
            background: var(--bs-tertiary-bg, #f8f9fa);
            border-radius: 8px 8px 0 0;
            flex-wrap: wrap;
        }

        .ig-header .ig-name-input {
            font-size: 1.1rem;
            font-weight: 600;
            border: 1px solid #ccc;
            background: white;
            padding: 0.25rem 0.5rem;
            border-radius: 3px;
            min-width: 250px;
        }

        .ig-header .ig-name-input:focus {
            border-color: #007cba;
            background: white;
            outline: none;
            box-shadow: 0 0 3px rgba(0, 124, 186, 0.3);
        }

        .ig-body {
            padding: 1rem;
        }

        .ig-settings {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
            align-items: center;
        }

        .ig-settings label {
            font-weight: 500;
            margin-right: 0.25rem;
        }

        .member-table {
            width: 100%;
            border-collapse: collapse;
        }

        .member-table th {
            background: var(--bs-tertiary-bg, #f8f9fa);
            padding: 0.5rem 0.75rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .member-table td {
            padding: 0.5rem 0.75rem;
            vertical-align: middle;
            border-bottom: 1px solid var(--bs-border-color-translucent, rgba(0, 0, 0, 0.1));
        }

        .member-table tr:last-child td {
            border-bottom: none;
        }

        .btn-remove-member {
            color: #dc3545;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1.1rem;
            padding: 0.25rem;
        }

        .btn-remove-member:hover {
            color: #a71d2a;
        }

        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .toolbar-left,
        .toolbar-right {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .no-groups-msg {
            text-align: center;
            padding: 3rem;
            color: var(--bs-secondary-color, #6c757d);
        }

        .no-groups-msg i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 0.25rem;
        }

        .status-ok {
            background: #28a745;
        }

        .status-error {
            background: #dc3545;
        }

        .status-unknown {
            background: #6c757d;
        }

        .volume-slider {
            width: 80px;
            display: inline-block;
            vertical-align: middle;
        }

        .volume-value {
            display: inline-block;
            width: 35px;
            text-align: right;
            font-size: 0.85rem;
        }

        .mute-btn {
            border: none;
            background: none;
            cursor: pointer;
            font-size: 1rem;
            padding: 0.25rem 0.5rem;
            border-radius: 3px;
        }

        .mute-btn.muted {
            color: #dc3545;
        }

        .mute-btn:not(.muted) {
            color: #28a745;
        }

        .output-routing {
            margin-top: 0.75rem;
            padding: 0.75rem;
            border: 1px solid var(--bs-border-color-translucent, rgba(0, 0, 0, 0.1));
            border-radius: 6px;
            background: var(--bs-tertiary-bg, #f8f9fa);
        }

        .output-routing label {
            font-weight: 500;
            margin-bottom: 0.5rem;
            display: block;
        }

        .output-routing .form-check {
            margin-right: 1rem;
            display: inline-block;
        }

        .alsa-warning {
            text-align: center;
            padding: 3rem;
        }

        .btn-group-action {
            font-weight: 500;
        }

        <?php if ($modalMode) { ?>
            body {
                overflow-y: auto !important;
            }

            .modal {
                z-index: 99999 !important;
            }

            .modal-backdrop {
                z-index: 99998 !important;
            }

        <?php } ?>
    </style>
</head>

<body<?php if ($modalMode)
    echo ' style="margin:0;padding:1rem;background:#fff;color:#212529;"'; ?>>
    <?php if (!$modalMode) { ?>
        <div id="bodyWrapper">
            <?php
            $activeParentMenuItem = 'status';
            include 'menu.inc';
            ?>
            <div class="mainContainer">
                <h1 class="title">Input Mixing</h1>
                <div class="pageContent">
                <?php } ?>

                <?php
                $audioBackend = isset($settings['AudioBackend']) ? $settings['AudioBackend'] : 'alsa';
                if ($audioBackend !== 'pipewire') {
                    ?>
                    <div class="alsa-warning">
                        <i class="fas fa-exclamation-triangle fa-2x" style="color: var(--bs-warning, #ffc107);"></i>
                        <h4>PipeWire Backend Required</h4>
                        <p>Input Mixing requires the PipeWire audio backend.<br>
                            Currently using: <strong><?= htmlspecialchars(ucfirst($audioBackend)) ?></strong></p>
                        <p>Change to PipeWire in <a href="settings.php?tab=Audio%2FVideo">FPP Settings &rarr;
                                Audio/Video</a>,
                            then return here to configure input mixing.</p>
                    </div>
                <?php } else { ?>

                    <div id="pipewireStatus" class="toolbar">
                        <div class="toolbar-left">
                            <span id="pwStatus"><span class="status-indicator status-unknown"></span> Checking PipeWire
                                status...</span>
                        </div>
                        <div class="toolbar-right">
                            <button class="buttons btn-outline-success btn-group-action" onclick="AddInputGroup()">
                                <i class="fas fa-plus"></i> Add Input Group
                            </button>
                            <button class="buttons" onclick="SaveInputGroups()">
                                <i class="fas fa-save"></i> Save
                            </button>
                            <button class="buttons btn-outline-primary" onclick="ApplyInputGroups()">
                                <i class="fas fa-sync"></i> Save &amp; Apply
                            </button>
                        </div>
                    </div>

                    <div id="inputGroupsContainer">
                        <div class="no-groups-msg" id="noGroupsMsg">
                            <i class="fas fa-sliders-h"></i>
                            <h4>No Input Groups Configured</h4>
                            <p>Create input groups (mix buses) to combine multiple audio sources.<br>
                                Route fppd streams, ALSA line-in, and AES67 receives into mix buses,<br>
                                then send them to your Audio Output Groups.</p>
                            <button class="buttons btn-outline-success" onclick="AddInputGroup()">
                                <i class="fas fa-plus"></i> Create First Input Group
                            </button>
                        </div>
                    </div>

                    <div id="bottomToolbar" class="toolbar" style="display:none; margin-top:1rem;">
                        <div class="toolbar-left"></div>
                        <div class="toolbar-right">
                            <button class="buttons" onclick="SaveInputGroups()">
                                <i class="fas fa-save"></i> Save
                            </button>
                            <button class="buttons btn-outline-primary" onclick="ApplyInputGroups()">
                                <i class="fas fa-sync"></i> Save &amp; Apply
                            </button>
                        </div>
                    </div>

                <?php } ?>

                <?php if (!$modalMode) { ?>
                </div>
            </div>
        </div>

        <?php include 'common/footer.inc'; ?>
    <?php } ?>

    <script>
        // ─── State ─────────────────────────────────────────────────────
        var inputGroups = { inputGroups: [] };
        var availableSources = [];
        var availableOutputGroups = [];
        var availableCards = [];

        // ─── Init ──────────────────────────────────────────────────────
        $(document).ready(function () {
            CheckPipeWireStatus();
            LoadAll();
        });

        function CheckPipeWireStatus() {
            $.get('/api/pipewire/audio/sinks', function (data) {
                var hasSinks = Array.isArray(data) && data.length > 0;
                $('#pwStatus').html(
                    '<span class="status-indicator ' + (hasSinks ? 'status-ok' : 'status-error') + '"></span> ' +
                    (hasSinks ? 'PipeWire running (' + data.length + ' sinks)' : 'PipeWire not detected')
                );
            }).fail(function () {
                $('#pwStatus').html(
                    '<span class="status-indicator status-error"></span> Cannot reach PipeWire API'
                );
            });
        }

        function LoadAll() {
            // Load all data in parallel
            var p1 = $.get('/api/pipewire/audio/input-groups');
            var p2 = $.get('/api/pipewire/audio/sources');
            var p3 = $.get('/api/pipewire/audio/groups');

            $.when(p1, p2, p3).done(function (r1, r2, r3) {
                inputGroups = r1[0];
                if (!inputGroups || !inputGroups.inputGroups) {
                    inputGroups = { inputGroups: [] };
                }
                availableSources = Array.isArray(r2[0]) ? r2[0] : [];
                var ogData = r3[0];
                availableOutputGroups = (ogData && ogData.groups) ? ogData.groups : [];

                RenderAll();
            }).fail(function () {
                // Load what we can
                $.get('/api/pipewire/audio/input-groups', function (data) {
                    inputGroups = data || { inputGroups: [] };
                    RenderAll();
                });
            });
        }

        // ─── Render ────────────────────────────────────────────────────
        function RenderAll() {
            var container = $('#inputGroupsContainer');
            container.empty();

            if (!inputGroups.inputGroups || inputGroups.inputGroups.length === 0) {
                container.html(
                    '<div class="no-groups-msg" id="noGroupsMsg">' +
                    '<i class="fas fa-sliders-h"></i>' +
                    '<h4>No Input Groups Configured</h4>' +
                    '<p>Create input groups (mix buses) to combine multiple audio sources.<br>' +
                    'Route fppd streams, ALSA line-in, and AES67 receives into mix buses,<br>' +
                    'then send them to your Audio Output Groups.</p>' +
                    '<button class="buttons btn-outline-success" onclick="AddInputGroup()">' +
                    '<i class="fas fa-plus"></i> Create First Input Group</button>' +
                    '</div>'
                );
                $('#bottomToolbar').hide();
                return;
            }

            inputGroups.inputGroups.forEach(function (ig, idx) {
                container.append(RenderInputGroup(ig, idx));
            });

            $('#bottomToolbar').show();
        }

        function RenderInputGroup(ig, idx) {
            var enabled = ig.enabled !== false;
            var html = '<div class="ig-card' + (!enabled ? ' disabled-group' : '') + '" data-idx="' + idx + '">';

            // Header
            html += '<div class="ig-header">';
            html += '<input type="text" class="ig-name-input" value="' + EscapeAttr(ig.name || 'Input Group') + '" ' +
                'onchange="UpdateGroupField(' + idx + ', \'name\', this.value)">';
            html += '<label style="margin:0;cursor:pointer;"><input type="checkbox" ' +
                (enabled ? 'checked' : '') + ' onchange="UpdateGroupField(' + idx + ', \'enabled\', this.checked)"> Enabled</label>';
            html += '<select class="form-select form-select-sm" style="width:auto;" onchange="UpdateGroupField(' + idx + ', \'channels\', parseInt(this.value))">';
            [2, 1, 4, 6, 8].forEach(function (ch) {
                html += '<option value="' + ch + '"' + (ig.channels == ch ? ' selected' : '') + '>' + ch + ' ch</option>';
            });
            html += '</select>';
            html += '<div style="margin-left:auto;display:flex;gap:0.5rem;">';
            html += '<button class="buttons btn-outline-success btn-sm" onclick="AddMember(' + idx + ')">' +
                '<i class="fas fa-plus"></i> Add Source</button>';
            html += '<button class="buttons btn-outline-danger btn-sm" onclick="RemoveGroup(' + idx + ')">' +
                '<i class="fas fa-trash"></i></button>';
            html += '</div>';
            html += '</div>';

            // Body
            html += '<div class="ig-body">';

            // Members table
            var members = ig.members || [];
            if (members.length > 0) {
                html += '<table class="member-table">';
                html += '<thead><tr>';
                html += '<th>Type</th><th>Source</th><th>Name</th><th>Volume</th><th>Mute</th><th></th>';
                html += '</tr></thead><tbody>';

                members.forEach(function (mbr, mi) {
                    html += RenderMember(idx, mi, mbr);
                });

                html += '</tbody></table>';
            } else {
                html += '<div style="text-align:center;padding:1rem;color:#6c757d;">' +
                    '<i class="fas fa-info-circle"></i> No sources added. Click "Add Source" to add audio inputs.' +
                    '</div>';
            }

            // Output routing
            html += '<div class="output-routing">';
            html += '<label><i class="fas fa-arrow-right"></i> Route to Output Groups:</label>';
            html += '<div>';
            var outputs = ig.outputs || [];
            if (availableOutputGroups.length > 0) {
                availableOutputGroups.forEach(function (og) {
                    if (!og.enabled) return;
                    var checked = outputs.indexOf(og.id) !== -1;
                    html += '<div class="form-check">';
                    html += '<input class="form-check-input" type="checkbox" ' +
                        (checked ? 'checked' : '') +
                        ' onchange="ToggleOutput(' + idx + ', ' + og.id + ', this.checked)">';
                    html += '<label class="form-check-label">' + EscapeHtml(og.name || 'Group ' + og.id) + '</label>';
                    html += '</div>';
                });
            } else {
                html += '<span style="color:#6c757d;">No output groups configured. ' +
                    '<a href="pipewire-audio.php">Create output groups first.</a></span>';
            }
            html += '</div></div>';

            html += '</div>'; // ig-body
            html += '</div>'; // ig-card

            return html;
        }

        function RenderMember(groupIdx, memberIdx, mbr) {
            var type = mbr.type || 'fppd_stream';
            var html = '<tr>';

            // Type selector
            html += '<td><select class="form-select form-select-sm" style="width:auto;" ' +
                'onchange="UpdateMemberField(' + groupIdx + ',' + memberIdx + ',\'type\',this.value); RenderAll();">';
            html += '<option value="fppd_stream"' + (type === 'fppd_stream' ? ' selected' : '') + '>fppd Stream</option>';
            html += '<option value="capture"' + (type === 'capture' ? ' selected' : '') + '>ALSA Capture</option>';
            html += '<option value="aes67_receive"' + (type === 'aes67_receive' ? ' selected' : '') + '>AES67 Receive</option>';
            html += '</select></td>';

            // Source selector
            html += '<td>';
            if (type === 'fppd_stream') {
                html += '<select class="form-select form-select-sm" style="width:auto;" ' +
                    'onchange="UpdateMemberField(' + groupIdx + ',' + memberIdx + ',\'sourceId\',this.value)">';
                html += '<option value="fppd_stream_1"' + ((mbr.sourceId || 'fppd_stream_1') === 'fppd_stream_1' ? ' selected' : '') + '>fppd_stream_1 (default)</option>';
                html += '</select>';
            } else if (type === 'capture') {
                html += '<select class="form-select form-select-sm" style="width:auto;" ' +
                    'onchange="UpdateMemberField(' + groupIdx + ',' + memberIdx + ',\'cardId\',this.value)">';
                html += '<option value="">-- Select capture device --</option>';
                availableSources.forEach(function (src) {
                    var sel = (mbr.cardId === src.cardId) ? ' selected' : '';
                    html += '<option value="' + EscapeAttr(src.cardId) + '"' + sel + '>' +
                        EscapeHtml(src.description || src.name) +
                        ' (' + src.channels + 'ch)</option>';
                });
                html += '</select>';
            } else if (type === 'aes67_receive') {
                html += '<input type="text" class="form-control form-control-sm" style="width:180px;" ' +
                    'placeholder="AES67 instance ID" value="' + EscapeAttr(mbr.instanceId || '') + '" ' +
                    'onchange="UpdateMemberField(' + groupIdx + ',' + memberIdx + ',\'instanceId\',this.value)">';
            }
            html += '</td>';

            // Name
            html += '<td><input type="text" class="form-control form-control-sm" style="width:150px;" ' +
                'value="' + EscapeAttr(mbr.name || '') + '" placeholder="Label" ' +
                'onchange="UpdateMemberField(' + groupIdx + ',' + memberIdx + ',\'name\',this.value)"></td>';

            // Volume
            var vol = mbr.volume !== undefined ? mbr.volume : 100;
            html += '<td>';
            html += '<input type="range" class="volume-slider" min="0" max="100" value="' + vol + '" ' +
                'oninput="UpdateMemberField(' + groupIdx + ',' + memberIdx + ',\'volume\',parseInt(this.value));' +
                'this.nextElementSibling.textContent=this.value+\'%\'">';
            html += '<span class="volume-value">' + vol + '%</span>';
            html += '</td>';

            // Mute
            var muted = mbr.mute === true;
            html += '<td>';
            html += '<button class="mute-btn ' + (muted ? 'muted' : '') + '" ' +
                'onclick="ToggleMute(' + groupIdx + ',' + memberIdx + ')" title="' + (muted ? 'Unmute' : 'Mute') + '">';
            html += '<i class="fas ' + (muted ? 'fa-volume-mute' : 'fa-volume-up') + '"></i>';
            html += '</button>';
            html += '</td>';

            // Remove
            html += '<td><button class="btn-remove-member" onclick="RemoveMember(' + groupIdx + ',' + memberIdx + ')" title="Remove source">';
            html += '<i class="fas fa-times"></i></button></td>';

            html += '</tr>';
            return html;
        }

        // ─── Actions ───────────────────────────────────────────────────
        function AddInputGroup() {
            var newId = 1;
            inputGroups.inputGroups.forEach(function (g) {
                if (g.id >= newId) newId = g.id + 1;
            });

            inputGroups.inputGroups.push({
                id: newId,
                name: "Input Group " + newId,
                enabled: true,
                channels: 2,
                volume: 100,
                members: [
                    {
                        type: "fppd_stream",
                        sourceId: "fppd_stream_1",
                        name: "Media Playback",
                        volume: 100,
                        mute: false
                    }
                ],
                outputs: []
            });

            RenderAll();
        }

        function RemoveGroup(idx) {
            if (!confirm('Remove this input group?')) return;
            inputGroups.inputGroups.splice(idx, 1);
            RenderAll();
        }

        function UpdateGroupField(idx, field, value) {
            inputGroups.inputGroups[idx][field] = value;
            if (field === 'enabled') {
                RenderAll();
            }
        }

        function AddMember(groupIdx) {
            if (!inputGroups.inputGroups[groupIdx].members) {
                inputGroups.inputGroups[groupIdx].members = [];
            }
            inputGroups.inputGroups[groupIdx].members.push({
                type: "capture",
                cardId: "",
                name: "",
                volume: 100,
                mute: false
            });
            RenderAll();
        }

        function RemoveMember(groupIdx, memberIdx) {
            inputGroups.inputGroups[groupIdx].members.splice(memberIdx, 1);
            RenderAll();
        }

        function UpdateMemberField(groupIdx, memberIdx, field, value) {
            inputGroups.inputGroups[groupIdx].members[memberIdx][field] = value;
        }

        function ToggleMute(groupIdx, memberIdx) {
            var mbr = inputGroups.inputGroups[groupIdx].members[memberIdx];
            mbr.mute = !mbr.mute;
            RenderAll();
        }

        function ToggleOutput(groupIdx, outputGroupId, checked) {
            var ig = inputGroups.inputGroups[groupIdx];
            if (!ig.outputs) ig.outputs = [];

            if (checked) {
                if (ig.outputs.indexOf(outputGroupId) === -1) {
                    ig.outputs.push(outputGroupId);
                }
            } else {
                ig.outputs = ig.outputs.filter(function (id) { return id !== outputGroupId; });
            }
        }

        // ─── Save & Apply ──────────────────────────────────────────────
        function SaveInputGroups() {
            $.ajax({
                url: '/api/pipewire/audio/input-groups',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(inputGroups),
                success: function (result) {
                    if (result.data) {
                        inputGroups = result.data;
                    }
                    $.jGrowl('Input groups saved.', { theme: 'success' });
                },
                error: function (xhr) {
                    var msg = 'Save failed';
                    try { msg = JSON.parse(xhr.responseText).message || msg; } catch (e) { }
                    $.jGrowl(msg, { theme: 'danger' });
                }
            });
        }

        function ApplyInputGroups() {
            // Save first, then apply
            $.ajax({
                url: '/api/pipewire/audio/input-groups',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(inputGroups),
                success: function (saveResult) {
                    if (saveResult.data) {
                        inputGroups = saveResult.data;
                    }
                    $.jGrowl('Saving input groups...', { theme: 'info' });

                    // Now apply
                    $.ajax({
                        url: '/api/pipewire/audio/input-groups/apply',
                        type: 'POST',
                        contentType: 'application/json',
                        data: '{}',
                        success: function (applyResult) {
                            $.jGrowl(applyResult.message || 'Input groups applied.', { theme: 'success' });
                            CheckPipeWireStatus();
                        },
                        error: function (xhr) {
                            var msg = 'Apply failed';
                            try { msg = JSON.parse(xhr.responseText).message || msg; } catch (e) { }
                            $.jGrowl(msg, { theme: 'danger' });
                        }
                    });
                },
                error: function (xhr) {
                    var msg = 'Save failed';
                    try { msg = JSON.parse(xhr.responseText).message || msg; } catch (e) { }
                    $.jGrowl(msg, { theme: 'danger' });
                }
            });
        }

        // ─── Helpers ───────────────────────────────────────────────────
        function EscapeHtml(str) {
            if (!str) return '';
            return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function EscapeAttr(str) {
            if (!str) return '';
            return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }
    </script>
</body>

</html>
