<!DOCTYPE html>
<html lang="en">

<head>
    <?php
    include 'common/htmlMeta.inc';
    require_once "common.php";
    require_once 'config.php';
    include 'common/menuHead.inc';
    ?>

    <title><? echo $pageTitle; ?> - PipeWire Audio Groups</title>

    <style>
        .group-card {
            border: 1px solid var(--bs-border-color, #dee2e6);
            border-radius: 8px;
            margin-bottom: 1.5rem;
            background: var(--bs-body-bg, #fff);
        }

        .group-card.disabled-group {
            opacity: 0.6;
        }

        .group-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--bs-border-color, #dee2e6);
            background: var(--bs-tertiary-bg, #f8f9fa);
            border-radius: 8px 8px 0 0;
            flex-wrap: wrap;
        }

        .group-header .group-name-input {
            font-size: 1.1rem;
            font-weight: 600;
            border: 1px solid transparent;
            background: transparent;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            min-width: 250px;
        }

        .group-header .group-name-input:focus {
            border-color: var(--bs-primary, #0d6efd);
            background: var(--bs-body-bg, #fff);
            outline: none;
        }

        .group-body {
            padding: 1rem;
        }

        .group-settings {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
            align-items: center;
        }

        .group-settings label {
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
            border-bottom: 2px solid var(--bs-border-color, #dee2e6);
            font-weight: 600;
            font-size: 0.9rem;
        }

        .member-table td {
            padding: 0.5rem 0.75rem;
            border-bottom: 1px solid var(--bs-border-color, #dee2e6);
            vertical-align: middle;
        }

        .member-table tr:last-child td {
            border-bottom: none;
        }

        .member-table tr:hover {
            background: var(--bs-tertiary-bg, rgba(0, 0, 0, 0.02));
        }

        .channel-map-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 0.25rem;
        }

        .channel-map-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.85rem;
        }

        .channel-map-item select {
            font-size: 0.85rem;
            padding: 0.15rem 0.3rem;
        }

        .volume-slider-container {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            min-width: 200px;
        }

        .volume-slider-container input[type="range"] {
            flex: 1;
        }

        .volume-value {
            min-width: 40px;
            text-align: right;
            font-size: 0.9rem;
        }

        .btn-group-action {
            padding: 0.25rem 0.5rem;
            font-size: 0.85rem;
        }

        .no-groups-msg {
            text-align: center;
            padding: 3rem;
            color: var(--bs-secondary-color, #6c757d);
        }

        .no-groups-msg i {
            font-size: 3rem;
            margin-bottom: 1rem;
            display: block;
        }

        .pipewire-badge {
            font-size: 0.75rem;
            padding: 0.2rem 0.5rem;
            vertical-align: middle;
        }

        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 0.5rem;
        }

        .status-running {
            background: #28a745;
        }

        .status-stopped {
            background: #dc3545;
        }

        .status-unknown {
            background: #ffc107;
        }

        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .toolbar-left,
        .toolbar-right {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .alsa-warning {
            background: var(--bs-warning-bg-subtle, #fff3cd);
            border: 1px solid var(--bs-warning-border-subtle, #ffecb5);
            border-radius: 8px;
            padding: 1.5rem;
            text-align: center;
            margin: 2rem 0;
        }

        /* EQ Controls */
        .eq-toggle-btn {
            font-size: 0.78rem;
            padding: 0.15rem 0.5rem;
        }

        .eq-panel {
            background: var(--bs-tertiary-bg, #f8f9fa);
            border: 1px solid var(--bs-border-color, #dee2e6);
            border-radius: 6px;
            padding: 0.75rem;
        }

        .eq-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }

        .eq-header-label {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .eq-band-header,
        .eq-band-row {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.2rem 0;
        }

        .eq-band-header {
            font-weight: 600;
            font-size: 0.78rem;
            color: var(--bs-secondary-color, #6c757d);
            border-bottom: 1px solid var(--bs-border-color, #dee2e6);
            margin-bottom: 0.25rem;
        }

        .eq-col-num {
            min-width: 20px;
            text-align: center;
        }

        .eq-col-type {
            min-width: 100px;
        }

        .eq-col-freq {
            min-width: 80px;
        }

        .eq-col-gain {
            min-width: 160px;
        }

        .eq-col-q {
            min-width: 60px;
        }

        .eq-col-action {
            min-width: 30px;
        }

        .eq-gain-slider {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .eq-gain-slider input[type="range"] {
            width: 100px;
        }

        .eq-gain-value {
            font-size: 0.8rem;
            min-width: 40px;
            text-align: right;
            font-family: monospace;
        }

        .eq-band-row select,
        .eq-band-row input[type="number"] {
            font-size: 0.8rem;
            padding: 0.15rem 0.3rem;
        }
    </style>
</head>

<body>
    <div id="bodyWrapper">
        <?php
        $activeParentMenuItem = 'status';
        include 'menu.inc';
        ?>
        <div class="mainContainer">
            <h1 class="title">PipeWire Audio Groups</h1>
            <div class="pageContent">

                <?php
                $audioBackend = isset($settings['AudioBackend']) ? $settings['AudioBackend'] : 'alsa';
                if ($audioBackend !== 'pipewire') {
                    ?>
                    <div class="alsa-warning">
                        <i class="fas fa-exclamation-triangle fa-2x" style="color: var(--bs-warning, #ffc107);"></i>
                        <h4>PipeWire Backend Required</h4>
                        <p>Audio Output Groups require the PipeWire audio backend.<br>
                            Currently using: <strong><?= htmlspecialchars(ucfirst($audioBackend)) ?></strong></p>
                        <p>Change to PipeWire in <a href="settings.php?tab=Audio%2FVideo">FPP Settings &rarr;
                                Audio/Video</a>,
                            then return here to configure audio groups.</p>
                    </div>
                <?php } else { ?>

                    <div id="pipewireStatus" class="toolbar">
                        <div class="toolbar-left">
                            <span id="pwStatus"><span class="status-indicator status-unknown"></span> Checking PipeWire
                                status...</span>
                        </div>
                        <div class="toolbar-right">
                            <button class="buttons btn-outline-success btn-group-action" onclick="AddGroup()">
                                <i class="fas fa-plus"></i> Add Group
                            </button>
                            <button class="buttons" onclick="SaveGroups()">
                                <i class="fas fa-save"></i> Save
                            </button>
                            <button class="buttons btn-outline-primary" onclick="ApplyGroups()">
                                <i class="fas fa-sync"></i> Save &amp; Apply
                            </button>
                        </div>
                    </div>

                    <div id="groupsContainer">
                        <div class="no-groups-msg" id="noGroupsMsg">
                            <i class="fas fa-layer-group"></i>
                            <h4>No Audio Groups Configured</h4>
                            <p>Create audio output groups to combine multiple sound cards into virtual sinks.<br>
                                Each group becomes an independent audio output that can be targeted by different streams.
                            </p>
                            <button class="buttons btn-outline-success" onclick="AddGroup()">
                                <i class="fas fa-plus"></i> Create First Group
                            </button>
                        </div>
                    </div>

                    <div id="bottomToolbar" class="toolbar" style="display:none; margin-top:1rem;">
                        <div class="toolbar-left"></div>
                        <div class="toolbar-right">
                            <button class="buttons" onclick="SaveGroups()">
                                <i class="fas fa-save"></i> Save
                            </button>
                            <button class="buttons btn-outline-primary" onclick="ApplyGroups()">
                                <i class="fas fa-sync"></i> Save &amp; Apply
                            </button>
                        </div>
                    </div>

                <?php } ?>

            </div>
        </div>

        <?php include 'common/footer.inc'; ?>
    </div>

    <script>
        // Available ALSA cards cache
        var availableCards = [];
        // Current groups data
        var audioGroups = { groups: [] };
        // Next group ID counter
        var nextGroupId = 1;

        // PipeWire channel positions
        var CHANNEL_POSITIONS = {
            1: ['MONO'],
            2: ['FL', 'FR'],
            4: ['FL', 'FR', 'RL', 'RR'],
            6: ['FL', 'FR', 'FC', 'LFE', 'RL', 'RR'],
            8: ['FL', 'FR', 'FC', 'LFE', 'RL', 'RR', 'SL', 'SR']
        };

        var ALL_POSITIONS = ['FL', 'FR', 'FC', 'LFE', 'RL', 'RR', 'SL', 'SR',
            'AUX0', 'AUX1', 'AUX2', 'AUX3', 'AUX4', 'AUX5', 'AUX6', 'AUX7',
            'MONO'];

        // EQ filter types available in PipeWire filter-chain biquads
        var EQ_BAND_TYPES = [
            { value: 'bq_peaking', label: 'Peaking' },
            { value: 'bq_lowshelf', label: 'Low Shelf' },
            { value: 'bq_highshelf', label: 'High Shelf' },
            { value: 'bq_lowpass', label: 'Low Pass' },
            { value: 'bq_highpass', label: 'High Pass' },
            { value: 'bq_notch', label: 'Notch' },
            { value: 'bq_bandpass', label: 'Band Pass' },
            { value: 'bq_allpass', label: 'All Pass' }
        ];

        function DefaultEQBands() {
            return [
                { type: 'bq_lowshelf', freq: 60, gain: 0, q: 0.7 },
                { type: 'bq_peaking', freq: 250, gain: 0, q: 1.0 },
                { type: 'bq_peaking', freq: 1000, gain: 0, q: 1.0 },
                { type: 'bq_peaking', freq: 4000, gain: 0, q: 1.0 },
                { type: 'bq_highshelf', freq: 12000, gain: 0, q: 0.7 }
            ];
        }

        $(document).ready(function () {
            CheckPipeWireStatus();
            // Cards must be loaded before groups so the dropdowns can render
            LoadAvailableCards().then(function () {
                LoadGroups();
            });
        });

        /////////////////////////////////////////////////////////////////////////////
        // PipeWire status check
        function CheckPipeWireStatus() {
            $.getJSON('api/pipewire/audio/sinks')
                .done(function (data) {
                    var count = data ? data.length : 0;
                    $('#pwStatus').html(
                        '<span class="status-indicator status-running"></span>' +
                        'PipeWire running — ' + count + ' sink' + (count !== 1 ? 's' : '') + ' available'
                    );
                })
                .fail(function () {
                    $('#pwStatus').html(
                        '<span class="status-indicator status-stopped"></span>' +
                        'PipeWire not responding — ensure the service is running'
                    );
                });
        }

        /////////////////////////////////////////////////////////////////////////////
        // Load available ALSA cards
        function LoadAvailableCards() {
            return $.getJSON('api/pipewire/audio/cards')
                .done(function (data) {
                    availableCards = data || [];
                })
                .fail(function () {
                    availableCards = [];
                    console.error('Failed to load ALSA cards');
                });
        }

        /////////////////////////////////////////////////////////////////////////////
        // Load saved groups
        function LoadGroups() {
            $.getJSON('api/pipewire/audio/groups')
                .done(function (data) {
                    audioGroups = data || { groups: [] };
                    // Calculate next ID
                    nextGroupId = 1;
                    for (var i = 0; i < audioGroups.groups.length; i++) {
                        if (audioGroups.groups[i].id >= nextGroupId) {
                            nextGroupId = audioGroups.groups[i].id + 1;
                        }
                    }
                    RenderGroups();
                })
                .fail(function () {
                    audioGroups = { groups: [] };
                    RenderGroups();
                });
        }

        /////////////////////////////////////////////////////////////////////////////
        // Render all groups
        function RenderGroups() {
            var container = $('#groupsContainer');
            container.empty();

            if (audioGroups.groups.length === 0) {
                container.append(
                    '<div class="no-groups-msg" id="noGroupsMsg">' +
                    '<i class="fas fa-layer-group"></i>' +
                    '<h4>No Audio Groups Configured</h4>' +
                    '<p>Create audio output groups to combine multiple sound cards into virtual sinks.<br>' +
                    'Each group becomes an independent audio output that can be targeted by different streams.</p>' +
                    '<button class="buttons btn-outline-success" onclick="AddGroup()">' +
                    '<i class="fas fa-plus"></i> Create First Group</button>' +
                    '</div>'
                );
                $('#bottomToolbar').hide();
                return;
            }

            $('#bottomToolbar').show();

            for (var i = 0; i < audioGroups.groups.length; i++) {
                container.append(RenderGroupCard(audioGroups.groups[i], i));
            }

            // Auto-expand EQ panels for members with EQ enabled
            for (var i = 0; i < audioGroups.groups.length; i++) {
                var g = audioGroups.groups[i];
                if (g.members) {
                    for (var m = 0; m < g.members.length; m++) {
                        if (g.members[m].eq && g.members[m].eq.enabled) {
                            $('#eq-panel-row-' + i + '-' + m).show();
                        }
                    }
                }
            }
        }

        /////////////////////////////////////////////////////////////////////////////
        // Render a single group card
        function RenderGroupCard(group, index) {
            var enabledClass = group.enabled ? '' : ' disabled-group';
            var enabledChecked = group.enabled ? ' checked' : '';
            var latencyChecked = group.latencyCompensate ? ' checked' : '';

            var html = '<div class="group-card' + enabledClass + '" id="group-' + group.id + '" data-index="' + index + '">';

            // Header
            html += '<div class="group-header">';
            html += '<input type="checkbox" class="form-check-input" onchange="ToggleGroupEnabled(' + index + ', this.checked)"' + enabledChecked + ' title="Enable/Disable group">';
            html += '<input type="text" class="group-name-input" value="' + EscapeAttr(group.name) + '" onchange="UpdateGroupName(' + index + ', this.value)" placeholder="Group Name">';
            html += '<span class="badge bg-info pipewire-badge">Combine Sink: fpp_group_' + EscapeNodeName(group.name) + '</span>';
            html += '<div style="flex:1"></div>';
            html += '<button class="buttons btn-outline-danger btn-group-action" onclick="DeleteGroup(' + index + ')" title="Delete Group"><i class="fas fa-trash"></i></button>';
            html += '</div>';

            // Body
            html += '<div class="group-body">';

            // Group settings
            html += '<div class="group-settings">';
            html += '<div>';
            html += '<label>Group Channels:</label>';
            html += '<select class="form-select form-select-sm" style="display:inline-block;width:auto;" onchange="UpdateGroupChannels(' + index + ', parseInt(this.value))">';
            var channelOptions = [2, 4, 6, 8];
            for (var c = 0; c < channelOptions.length; c++) {
                var sel = (group.channels === channelOptions[c]) ? ' selected' : '';
                html += '<option value="' + channelOptions[c] + '"' + sel + '>' + channelOptions[c] + 'ch (' + ChannelLayoutName(channelOptions[c]) + ')</option>';
            }
            html += '</select>';
            html += '</div>';

            html += '<div>';
            html += '<label><input type="checkbox" class="form-check-input" onchange="UpdateGroupLatency(' + index + ', this.checked)"' + latencyChecked + '> Latency Compensation</label>';
            html += '</div>';

            // Volume control for the group sink
            html += '<div class="volume-slider-container">';
            html += '<label><i class="fas fa-volume-up"></i></label>';
            html += '<input type="range" class="form-range" min="0" max="100" value="' + (group.volume || 100) + '" oninput="UpdateGroupVolumeDisplay(this); ScheduleGroupVolume(' + index + ', this.value)">';
            html += '<span class="volume-value">' + (group.volume || 100) + '%</span>';
            html += '</div>';

            html += '</div>';

            // Members table
            html += '<table class="member-table">';
            html += '<thead><tr>';
            html += '<th style="width:30px">#</th>';
            html += '<th>Sound Card</th>';
            html += '<th>Card Channels</th>';
            html += '<th>Channel Mapping</th>';
            html += '<th>Volume</th>';
            html += '<th style="width:60px"></th>';
            html += '</tr></thead>';
            html += '<tbody id="members-' + group.id + '">';

            if (group.members && group.members.length > 0) {
                for (var m = 0; m < group.members.length; m++) {
                    html += RenderMemberRow(group, index, m);
                }
            } else {
                html += '<tr class="no-members-row"><td colspan="6" style="text-align:center;color:var(--bs-secondary-color,#6c757d);padding:1.5rem;">';
                html += '<i class="fas fa-info-circle"></i> No sound cards added to this group yet';
                html += '</td></tr>';
            }

            html += '</tbody>';
            html += '</table>';

            html += '<div style="margin-top:0.75rem;">';
            html += '<button class="buttons btn-outline-success btn-group-action" onclick="AddMember(' + index + ')">';
            html += '<i class="fas fa-plus"></i> Add Sound Card</button>';
            html += '</div>';

            html += '</div>'; // group-body
            html += '</div>'; // group-card

            return html;
        }

        /////////////////////////////////////////////////////////////////////////////
        // Render a member row
        function RenderMemberRow(group, groupIndex, memberIndex) {
            var member = group.members[memberIndex];
            var cardSelect = BuildCardSelect(groupIndex, memberIndex, member.cardId);
            var channelMapping = BuildChannelMapping(group, groupIndex, memberIndex, member);
            var eqEnabled = member.eq && member.eq.enabled;

            var html = '<tr data-member="' + memberIndex + '">';
            html += '<td>' + (memberIndex + 1) + '</td>';
            html += '<td>' + cardSelect + '</td>';
            html += '<td>';
            html += '<select class="form-select form-select-sm" style="width:auto;display:inline-block;" onchange="UpdateMemberChannels(' + groupIndex + ',' + memberIndex + ', parseInt(this.value))">';
            var chOpts = [1, 2, 4, 6, 8];
            for (var c = 0; c < chOpts.length; c++) {
                var sel = (member.channels === chOpts[c]) ? ' selected' : '';
                html += '<option value="' + chOpts[c] + '"' + sel + '>' + chOpts[c] + '</option>';
            }
            html += '</select>';
            html += '</td>';
            html += '<td>' + channelMapping;
            // EQ toggle button
            html += '<div style="margin-top:0.35rem;">';
            html += '<button id="eq-btn-' + groupIndex + '-' + memberIndex + '" class="btn btn-sm ' + (eqEnabled ? 'btn-info' : 'btn-outline-secondary') + ' eq-toggle-btn" ';
            html += 'onclick="ToggleEQPanel(' + groupIndex + ',' + memberIndex + ')">';
            html += '<i class="fas fa-sliders-h"></i> EQ</button>';
            html += '</div>';
            html += '</td>';
            html += '<td>';
            html += '<div class="volume-slider-container">';
            html += '<input type="range" class="form-range" min="0" max="100" value="' + (member.volume || 100) + '" oninput="UpdateGroupVolumeDisplay(this); ScheduleMemberVolume(' + groupIndex + ',' + memberIndex + ', this.value)" style="min-width:80px;">';
            html += '<span class="volume-value">' + (member.volume || 100) + '%</span>';
            html += '</div>';
            html += '</td>';
            html += '<td>';
            html += '<button class="buttons btn-outline-danger btn-group-action" onclick="RemoveMember(' + groupIndex + ',' + memberIndex + ')" title="Remove"><i class="fas fa-times"></i></button>';
            html += '</td>';
            html += '</tr>';

            // EQ panel row (expandable)
            html += '<tr id="eq-panel-row-' + groupIndex + '-' + memberIndex + '" style="display:none;">';
            html += '<td colspan="6">';
            html += '<div id="eq-panel-content-' + groupIndex + '-' + memberIndex + '">';
            html += BuildEQPanel(groupIndex, memberIndex, member);
            html += '</div>';
            html += '</td></tr>';

            return html;
        }

        /////////////////////////////////////////////////////////////////////////////
        // Build card select dropdown
        function BuildCardSelect(groupIndex, memberIndex, selectedCardId) {
            var html = '<select class="form-select form-select-sm" style="width:auto;display:inline-block;" ';
            html += 'onchange="UpdateMemberCard(' + groupIndex + ',' + memberIndex + ', this.value)">';
            html += '<option value="">-- Select Card --</option>';

            for (var i = 0; i < availableCards.length; i++) {
                var card = availableCards[i];
                var sel = (card.cardId === selectedCardId) ? ' selected' : '';
                var label = EscapeHtml(card.cardName) + ' [' + EscapeHtml(card.cardId) + ']';
                if (card.byPath) label += ' (' + EscapeHtml(card.byPath) + ')';
                html += '<option value="' + EscapeAttr(card.cardId) + '"' + sel + '>' + label + '</option>';
            }

            html += '</select>';
            return html;
        }

        /////////////////////////////////////////////////////////////////////////////
        // Build channel mapping controls
        function BuildChannelMapping(group, groupIndex, memberIndex, member) {
            var memberCh = member.channels || 2;
            var groupCh = group.channels || 2;
            var groupPositions = CHANNEL_POSITIONS[groupCh] || CHANNEL_POSITIONS[2];
            var memberPositions = CHANNEL_POSITIONS[memberCh] || CHANNEL_POSITIONS[2];

            var mapping = member.channelMapping || null;

            var html = '<div class="channel-map-grid">';

            for (var c = 0; c < memberCh && c < memberPositions.length; c++) {
                var cardCh = memberPositions[c];
                // What group channel is this card channel mapped to?
                var mappedTo = cardCh; // Default: same position
                if (mapping && mapping.cardChannels && mapping.groupChannels) {
                    var idx = -1;
                    for (var j = 0; j < mapping.cardChannels.length; j++) {
                        if (mapping.cardChannels[j] === cardCh) {
                            idx = j;
                            break;
                        }
                    }
                    if (idx >= 0 && idx < mapping.groupChannels.length) {
                        mappedTo = mapping.groupChannels[idx];
                    }
                }

                html += '<div class="channel-map-item">';
                html += '<span title="Card channel">' + cardCh + '</span>';
                html += '<i class="fas fa-arrow-right" style="font-size:0.7rem;color:var(--bs-secondary-color,#999);"></i>';
                html += '<select class="form-select form-select-sm" style="width:auto;padding:0.1rem 1.5rem 0.1rem 0.3rem;" ';
                html += 'onchange="UpdateChannelMap(' + groupIndex + ',' + memberIndex + ',' + c + ', this.value)" ';
                html += 'title="Map card channel ' + cardCh + ' to group position">';

                for (var p = 0; p < groupPositions.length; p++) {
                    var sel = (groupPositions[p] === mappedTo) ? ' selected' : '';
                    html += '<option value="' + groupPositions[p] + '"' + sel + '>' + groupPositions[p] + '</option>';
                }
                // Also offer AUX positions for advanced setups
                html += '<option value="">-- None --</option>';
                html += '</select>';
                html += '</div>';
            }

            html += '</div>';
            return html;
        }

        /////////////////////////////////////////////////////////////////////////////
        // EQ Panel Builder & Management
        function BuildEQPanel(groupIndex, memberIndex, member) {
            var eq = member.eq || { enabled: false, bands: DefaultEQBands() };
            var enabledChecked = eq.enabled ? ' checked' : '';

            var html = '<div class="eq-panel">';
            html += '<div class="eq-header">';
            html += '<div class="eq-header-label">';
            html += '<label class="form-check-label">';
            html += '<input type="checkbox" class="form-check-input"' + enabledChecked;
            html += ' onchange="ToggleEQ(' + groupIndex + ',' + memberIndex + ',this.checked)">';
            html += ' Parametric EQ</label>';
            html += '</div>';
            html += '<div>';
            html += '<button class="btn btn-sm btn-outline-success" onclick="AddEQBand(' + groupIndex + ',' + memberIndex + ')" title="Add Band">';
            html += '<i class="fas fa-plus"></i> Band</button>';
            html += '</div>';
            html += '</div>';

            html += '<div class="eq-bands-container">';
            if (eq.bands && eq.bands.length > 0) {
                html += '<div class="eq-band-header">';
                html += '<span class="eq-col-num">#</span>';
                html += '<span class="eq-col-type">Type</span>';
                html += '<span class="eq-col-freq">Freq (Hz)</span>';
                html += '<span class="eq-col-gain">Gain (dB)</span>';
                html += '<span class="eq-col-q">Q</span>';
                html += '<span class="eq-col-action"></span>';
                html += '</div>';
                for (var b = 0; b < eq.bands.length; b++) {
                    html += BuildEQBandRow(groupIndex, memberIndex, b, eq.bands[b]);
                }
            } else {
                html += '<div style="text-align:center;color:var(--bs-secondary-color,#6c757d);padding:0.5rem;">No EQ bands &mdash; click "+ Band" to add</div>';
            }
            html += '</div>';
            html += '</div>';
            return html;
        }

        function BuildEQBandRow(groupIndex, memberIndex, bandIndex, band) {
            var html = '<div class="eq-band-row">';
            html += '<span class="eq-col-num">' + (bandIndex + 1) + '</span>';

            // Type dropdown
            html += '<span class="eq-col-type"><select class="form-select form-select-sm" ';
            html += 'onchange="UpdateEQBand(' + groupIndex + ',' + memberIndex + ',' + bandIndex + ',\'type\',this.value)">';
            for (var t = 0; t < EQ_BAND_TYPES.length; t++) {
                var sel = (band.type === EQ_BAND_TYPES[t].value) ? ' selected' : '';
                html += '<option value="' + EQ_BAND_TYPES[t].value + '"' + sel + '>' + EQ_BAND_TYPES[t].label + '</option>';
            }
            html += '</select></span>';

            // Frequency
            html += '<span class="eq-col-freq"><input type="number" class="form-control form-control-sm" ';
            html += 'min="20" max="20000" step="1" value="' + (band.freq || 1000) + '" ';
            html += 'onchange="UpdateEQBand(' + groupIndex + ',' + memberIndex + ',' + bandIndex + ',\'freq\',parseFloat(this.value))" ';
            html += 'style="width:80px;"></span>';

            // Gain slider
            html += '<span class="eq-col-gain"><div class="eq-gain-slider">';
            html += '<input type="range" min="-24" max="24" step="0.5" value="' + (band.gain || 0) + '" ';
            html += 'oninput="UpdateEQGainDisplay(this); UpdateEQBand(' + groupIndex + ',' + memberIndex + ',' + bandIndex + ',\'gain\',parseFloat(this.value))">';
            html += '<span class="eq-gain-value">' + FormatGain(band.gain) + '</span>';
            html += '</div></span>';

            // Q factor
            html += '<span class="eq-col-q"><input type="number" class="form-control form-control-sm" ';
            html += 'min="0.1" max="30" step="0.1" value="' + (band.q || 1.0) + '" ';
            html += 'onchange="UpdateEQBand(' + groupIndex + ',' + memberIndex + ',' + bandIndex + ',\'q\',parseFloat(this.value))" ';
            html += 'style="width:65px;"></span>';

            // Remove button
            html += '<span class="eq-col-action"><button class="btn btn-sm btn-outline-danger" ';
            html += 'onclick="RemoveEQBand(' + groupIndex + ',' + memberIndex + ',' + bandIndex + ')" title="Remove Band">';
            html += '<i class="fas fa-times"></i></button></span>';

            html += '</div>';
            return html;
        }

        function ToggleEQPanel(groupIndex, memberIndex) {
            var row = $('#eq-panel-row-' + groupIndex + '-' + memberIndex);
            row.toggle();
        }

        function ToggleEQ(groupIndex, memberIndex, enabled) {
            var member = audioGroups.groups[groupIndex].members[memberIndex];
            if (!member.eq) {
                member.eq = { enabled: enabled, bands: DefaultEQBands() };
            } else {
                member.eq.enabled = enabled;
            }
            var btn = $('#eq-btn-' + groupIndex + '-' + memberIndex);
            if (enabled) {
                btn.removeClass('btn-outline-secondary').addClass('btn-info');
            } else {
                btn.removeClass('btn-info').addClass('btn-outline-secondary');
            }
        }

        function AddEQBand(groupIndex, memberIndex) {
            var member = audioGroups.groups[groupIndex].members[memberIndex];
            if (!member.eq) {
                member.eq = { enabled: false, bands: DefaultEQBands() };
            }
            member.eq.bands.push({ type: 'bq_peaking', freq: 1000, gain: 0, q: 1.0 });
            RefreshEQPanel(groupIndex, memberIndex);
        }

        function RemoveEQBand(groupIndex, memberIndex, bandIndex) {
            var member = audioGroups.groups[groupIndex].members[memberIndex];
            if (member.eq && member.eq.bands) {
                member.eq.bands.splice(bandIndex, 1);
            }
            RefreshEQPanel(groupIndex, memberIndex);
        }

        // Debounce timer for real-time EQ updates
        var eqUpdateTimers = {};

        function UpdateEQBand(groupIndex, memberIndex, bandIndex, field, value) {
            var member = audioGroups.groups[groupIndex].members[memberIndex];
            if (member.eq && member.eq.bands && member.eq.bands[bandIndex]) {
                member.eq.bands[bandIndex][field] = value;
            }
            // Debounced real-time push to running filter-chain
            ScheduleEQUpdate(groupIndex, memberIndex);
        }

        function UpdateEQGainDisplay(slider) {
            var val = parseFloat(slider.value);
            $(slider).siblings('.eq-gain-value').text(FormatGain(val));
        }

        // Push EQ params to the running PipeWire filter-chain in real time.
        // Debounced so rapid slider movements don't flood the API.
        function ScheduleEQUpdate(groupIndex, memberIndex) {
            var key = groupIndex + '_' + memberIndex;
            if (eqUpdateTimers[key]) clearTimeout(eqUpdateTimers[key]);
            eqUpdateTimers[key] = setTimeout(function () {
                SendEQUpdate(groupIndex, memberIndex);
            }, 80);
        }

        function SendEQUpdate(groupIndex, memberIndex) {
            var group = audioGroups.groups[groupIndex];
            var member = group.members[memberIndex];
            if (!member.eq || !member.eq.enabled || !member.eq.bands || !member.eq.bands.length) return;
            if (!member.cardId) return;

            $.ajax({
                url: 'api/pipewire/audio/eq/update',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    groupId: group.id,
                    cardId: member.cardId,
                    channels: member.channels || 2,
                    bands: member.eq.bands
                }),
                success: function (resp) {
                    if (resp && resp.status === 'NOT_RUNNING') {
                        // Filter not active yet — silent, user needs to Apply
                    }
                },
                error: function () {
                    // Silent — best-effort real-time preview
                }
            });
        }

        function FormatGain(gain) {
            var val = parseFloat(gain || 0);
            return (val >= 0 ? '+' : '') + val.toFixed(1);
        }

        function RefreshEQPanel(groupIndex, memberIndex) {
            var member = audioGroups.groups[groupIndex].members[memberIndex];
            var content = BuildEQPanel(groupIndex, memberIndex, member);
            $('#eq-panel-content-' + groupIndex + '-' + memberIndex).html(content);
        }

        /////////////////////////////////////////////////////////////////////////////
        // Group management functions
        function AddGroup() {
            var group = {
                id: nextGroupId++,
                name: "Audio Group " + audioGroups.groups.length + 1,
                enabled: true,
                channels: 2,
                latencyCompensate: false,
                volume: 100,
                members: []
            };
            audioGroups.groups.push(group);
            RenderGroups();
        }

        function DeleteGroup(index) {
            if (!confirm('Delete group "' + audioGroups.groups[index].name + '"?')) return;
            audioGroups.groups.splice(index, 1);
            RenderGroups();
        }

        function ToggleGroupEnabled(index, enabled) {
            audioGroups.groups[index].enabled = enabled;
            var card = $('#group-' + audioGroups.groups[index].id);
            if (enabled) {
                card.removeClass('disabled-group');
            } else {
                card.addClass('disabled-group');
            }
        }

        function UpdateGroupName(index, name) {
            audioGroups.groups[index].name = name;
            // Update the node name badge
            var card = $('#group-' + audioGroups.groups[index].id);
            card.find('.pipewire-badge').text('Combine Sink: fpp_group_' + EscapeNodeName(name));
        }

        function UpdateGroupChannels(index, channels) {
            audioGroups.groups[index].channels = channels;
            // Re-render to update channel mapping dropdowns
            RenderGroups();
        }

        function UpdateGroupLatency(index, enabled) {
            audioGroups.groups[index].latencyCompensate = enabled;
        }

        function UpdateGroupVolumeDisplay(slider) {
            $(slider).siblings('.volume-value').text(slider.value + '%');
        }

        /////////////////////////////////////////////////////////////////////////////
        // Member management functions
        function AddMember(groupIndex) {
            var member = {
                cardId: "",
                cardName: "",
                channels: 2,
                volume: 100,
                channelMapping: null,
                eq: { enabled: false, bands: DefaultEQBands() }
            };
            audioGroups.groups[groupIndex].members.push(member);
            RenderGroups();
        }

        function RemoveMember(groupIndex, memberIndex) {
            audioGroups.groups[groupIndex].members.splice(memberIndex, 1);
            RenderGroups();
        }

        function UpdateMemberCard(groupIndex, memberIndex, cardId) {
            var member = audioGroups.groups[groupIndex].members[memberIndex];
            member.cardId = cardId;

            // Find card info and auto-set channels
            for (var i = 0; i < availableCards.length; i++) {
                if (availableCards[i].cardId === cardId) {
                    member.cardName = availableCards[i].cardName;
                    member.channels = Math.min(availableCards[i].channels, 2); // Default to stereo
                    break;
                }
            }

            // Reset channel mapping
            member.channelMapping = BuildDefaultChannelMapping(member.channels, audioGroups.groups[groupIndex].channels);
            RenderGroups();
        }

        function UpdateMemberChannels(groupIndex, memberIndex, channels) {
            var member = audioGroups.groups[groupIndex].members[memberIndex];
            member.channels = channels;
            member.channelMapping = BuildDefaultChannelMapping(channels, audioGroups.groups[groupIndex].channels);
            RenderGroups();
        }

        function UpdateChannelMap(groupIndex, memberIndex, channelIndex, groupPosition) {
            var member = audioGroups.groups[groupIndex].members[memberIndex];
            var memberCh = member.channels || 2;
            var memberPositions = CHANNEL_POSITIONS[memberCh] || CHANNEL_POSITIONS[2];

            if (!member.channelMapping) {
                member.channelMapping = BuildDefaultChannelMapping(memberCh, audioGroups.groups[groupIndex].channels);
            }

            if (channelIndex < member.channelMapping.groupChannels.length) {
                member.channelMapping.groupChannels[channelIndex] = groupPosition;
            }
        }

        function BuildDefaultChannelMapping(memberChannels, groupChannels) {
            var memberPositions = CHANNEL_POSITIONS[memberChannels] || CHANNEL_POSITIONS[2];
            var groupPositions = CHANNEL_POSITIONS[groupChannels] || CHANNEL_POSITIONS[2];

            var cardChannels = [];
            var grpChannels = [];

            for (var i = 0; i < memberPositions.length; i++) {
                cardChannels.push(memberPositions[i]);
                // Map to same position if available in group, otherwise first available
                if (groupPositions.indexOf(memberPositions[i]) >= 0) {
                    grpChannels.push(memberPositions[i]);
                } else {
                    grpChannels.push(groupPositions[i % groupPositions.length]);
                }
            }

            return {
                cardChannels: cardChannels,
                groupChannels: grpChannels
            };
        }

        /////////////////////////////////////////////////////////////////////////////
        // Volume control — debounced for real-time slider tracking
        var volumeTimers = {};

        function ScheduleGroupVolume(groupIndex, volume) {
            audioGroups.groups[groupIndex].volume = parseInt(volume);
            var key = 'g_' + groupIndex;
            if (volumeTimers[key]) clearTimeout(volumeTimers[key]);
            volumeTimers[key] = setTimeout(function () {
                SetGroupVolume(groupIndex, volume);
            }, 60);
        }

        function ScheduleMemberVolume(groupIndex, memberIndex, volume) {
            audioGroups.groups[groupIndex].members[memberIndex].volume = parseInt(volume);
            var key = 'm_' + groupIndex + '_' + memberIndex;
            if (volumeTimers[key]) clearTimeout(volumeTimers[key]);
            volumeTimers[key] = setTimeout(function () {
                SetMemberVolume(groupIndex, memberIndex, volume);
            }, 60);
        }

        function SetGroupVolume(groupIndex, volume) {
            audioGroups.groups[groupIndex].volume = parseInt(volume);
            var group = audioGroups.groups[groupIndex];
            var sinkName = 'fpp_group_' + EscapeNodeName(group.name);
            SendVolumeCommand(sinkName, volume);
        }

        function SetMemberVolume(groupIndex, memberIndex, volume) {
            audioGroups.groups[groupIndex].members[memberIndex].volume = parseInt(volume);
            var member = audioGroups.groups[groupIndex].members[memberIndex];
            if (member.cardId) {
                // Find the actual PipeWire node name for this card
                var sinkName = null;
                for (var i = 0; i < availableCards.length; i++) {
                    if (availableCards[i].cardId === member.cardId) {
                        sinkName = availableCards[i].pwNodeName || null;
                        break;
                    }
                }
                if (sinkName) {
                    SendVolumeCommand(sinkName, volume);
                }
            }
        }

        function SendVolumeCommand(sinkName, volume) {
            $.ajax({
                url: 'api/pipewire/audio/group/volume',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ sink: sinkName, volume: parseInt(volume) }),
                error: function (xhr) {
                    console.warn('Volume command failed for ' + sinkName);
                }
            });
        }

        /////////////////////////////////////////////////////////////////////////////
        // Save / Apply
        function SaveGroups() {
            // Validate
            for (var i = 0; i < audioGroups.groups.length; i++) {
                var g = audioGroups.groups[i];
                if (!g.name || g.name.trim() === '') {
                    DialogError('Validation Error', 'Group ' + (i + 1) + ' must have a name.');
                    return;
                }
            }

            $.ajax({
                url: 'api/pipewire/audio/groups',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(audioGroups),
                success: function (data) {
                    if (data && data.data) {
                        audioGroups = data.data;
                    }
                    $.jGrowl('Audio groups saved', { themeState: 'highlight' });
                },
                error: function (xhr) {
                    DialogError('Save Error', 'Failed to save audio groups: ' + (xhr.responseText || 'Unknown error'));
                }
            });
        }

        function ApplyGroups() {
            // Save first, then apply
            $.ajax({
                url: 'api/pipewire/audio/groups',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(audioGroups),
                success: function (data) {
                    if (data && data.data) {
                        audioGroups = data.data;
                    }
                    $.jGrowl('Audio groups saved, applying...', { themeState: 'highlight' });

                    // Now apply (generates PipeWire configs and restarts)
                    $.ajax({
                        url: 'api/pipewire/audio/groups/apply',
                        method: 'POST',
                        success: function (applyData) {
                            var msg = 'PipeWire configuration applied successfully.';
                            if (applyData && applyData.activeGroup) {
                                msg += ' Active output: ' + applyData.activeGroup;
                            }
                            if (applyData && applyData.restartRequired) {
                                msg += '<br><br><b>FPPD must be restarted</b> for the audio output change to take effect.';
                                DialogOK('Configuration Applied', msg +
                                    '<br><br><button class="buttons btn-outline-primary" onclick="RestartFPPD()">' +
                                    '<i class="fas fa-redo"></i> Restart FPPD Now</button>');
                            } else {
                                $.jGrowl(msg, { themeState: 'highlight' });
                            }
                            // Refresh PipeWire status after a brief delay
                            setTimeout(function () {
                                CheckPipeWireStatus();
                            }, 3000);
                        },
                        error: function (xhr) {
                            DialogError('Apply Error', 'Failed to apply PipeWire configuration: ' + (xhr.responseText || 'Unknown error'));
                        }
                    });
                },
                error: function (xhr) {
                    DialogError('Save Error', 'Failed to save audio groups: ' + (xhr.responseText || 'Unknown error'));
                }
            });
        }

        /////////////////////////////////////////////////////////////////////////////
        // Utility functions
        function ChannelLayoutName(ch) {
            switch (ch) {
                case 1: return 'Mono';
                case 2: return 'Stereo';
                case 4: return 'Quad';
                case 6: return '5.1';
                case 8: return '7.1';
                default: return ch + 'ch';
            }
        }

        function EscapeNodeName(name) {
            return name.toLowerCase().replace(/[^a-z0-9_]/g, '_');
        }

        function RestartFPPD() {
            $.jGrowl('Restarting FPPD...', { themeState: 'highlight' });
            $.ajax({
                url: 'api/system/fppd/restart',
                method: 'GET',
                success: function () {
                    $.jGrowl('FPPD restarting — audio will use the new output group', { themeState: 'highlight' });
                    setTimeout(function () { CheckPipeWireStatus(); }, 5000);
                },
                error: function () {
                    $.jGrowl('FPPD restart requested', { themeState: 'highlight' });
                }
            });
        }

        function EscapeHtml(str) {
            if (!str) return '';
            return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function EscapeAttr(str) {
            if (!str) return '';
            return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }
    </script>
</body>

</html>