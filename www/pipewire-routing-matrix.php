<!DOCTYPE html>
<html lang="en">

<head>
    <?php
    $modalMode = isset($_GET['modal']) && $_GET['modal'] == '1';
    include 'common/htmlMeta.inc';
    require_once "common.php";
    require_once 'config.php';
    if (!$modalMode) {
        include 'common/menuHead.inc';
    }
    ?>

    <title><? echo $pageTitle; ?> - Audio Routing Matrix</title>

    <style>
        .routing-matrix-container {
            overflow-x: auto;
            margin-bottom: 1.5rem;
        }

        .routing-matrix {
            border-collapse: collapse;
            min-width: 100%;
        }

        .routing-matrix th,
        .routing-matrix td {
            border: 1px solid var(--bs-border-color, #dee2e6);
            padding: 0.5rem 0.75rem;
            text-align: center;
            vertical-align: middle;
        }

        .routing-matrix thead th {
            background: var(--bs-tertiary-bg, #f8f9fa);
            font-weight: 600;
            font-size: 0.85rem;
            white-space: nowrap;
        }

        .routing-matrix .ig-label {
            text-align: left;
            font-weight: 600;
            background: var(--bs-tertiary-bg, #f8f9fa);
            white-space: nowrap;
            min-width: 140px;
        }

        .routing-cell {
            min-width: 120px;
        }

        .routing-cell .form-check {
            margin-bottom: 0.25rem;
        }

        .routing-cell .volume-control {
            display: flex;
            align-items: center;
            gap: 4px;
            margin-top: 4px;
        }

        .routing-cell .volume-slider {
            width: 70px;
            height: 6px;
        }

        .routing-cell .volume-value {
            font-size: 0.75rem;
            color: var(--bs-secondary-color, #6c757d);
            min-width: 28px;
        }

        .routing-cell .btn-mute {
            padding: 0 4px;
            font-size: 0.7rem;
            line-height: 1.2;
        }

        .routing-cell.muted {
            opacity: 0.5;
        }

        .routing-cell.disconnected .volume-control {
            display: none;
        }

        .presets-section {
            border: 1px solid var(--bs-border-color, #dee2e6);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            background: var(--bs-body-bg, #fff);
        }

        .presets-section h5 {
            margin-bottom: 0.75rem;
        }

        .preset-list {
            max-height: 200px;
            overflow-y: auto;
        }

        .preset-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.35rem 0;
            border-bottom: 1px solid var(--bs-border-color-translucent, rgba(0, 0, 0, 0.05));
        }

        .preset-item:last-child {
            border-bottom: none;
        }

        .effects-section {
            border: 1px solid var(--bs-border-color, #dee2e6);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            background: var(--bs-body-bg, #fff);
        }

        .eq-band {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .eq-band input[type="number"] {
            width: 80px;
        }

        .eq-band input[type="range"] {
            width: 120px;
        }

        .eq-band .gain-value {
            min-width: 40px;
            text-align: right;
            font-size: 0.85rem;
        }

        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .dirty-indicator {
            display: none;
            color: #dc3545;
            font-size: 0.8rem;
            margin-left: 0.5rem;
        }

        .dirty-indicator.show {
            display: inline;
        }
    </style>
</head>

<body>
    <?php
    if (!$modalMode) {
        include 'common/menuHead.inc';
        include 'common/menuBody.inc';
    }
    ?>

    <div id="bodyWrapper">
        <div style="max-width: 1200px; margin: 0 auto; padding: 1rem;">
            <?php if (!$modalMode) { ?>
                <div class="titleArea">
                    <h1 class="title">Audio Routing Matrix</h1>
                </div>
            <?php } ?>

            <?php if (!isset($settings['AudioPipeWire']) || $settings['AudioPipeWire'] != '1') { ?>
                <div class="alsa-warning">
                    <p>The Routing Matrix requires PipeWire audio backend with Input Groups configured.<br>
                        <a href="settings.php#settings-audio">Enable PipeWire</a> first,
                        then <a href="pipewire-input-mixing.php">configure input groups</a>.
                    </p>
                </div>
            <?php } else { ?>

                <div id="routingContent">
                    <div class="mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <span class="text-muted">Route input groups to output groups with per-path volume control.</span>
                            <span class="dirty-indicator" id="dirtyIndicator">
                                <i class="fas fa-exclamation-circle"></i> Unsaved changes
                            </span>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-outline-secondary btn-sm" onclick="LoadData()">
                                <i class="fas fa-sync"></i> Refresh
                            </button>
                            <button class="btn btn-primary btn-sm" onclick="SaveAndApply()">
                                <i class="fas fa-check"></i> Save &amp; Apply
                            </button>
                        </div>
                    </div>

                    <!-- Routing Matrix Grid -->
                    <div class="routing-matrix-container" id="matrixContainer">
                        <p class="text-center text-muted py-3"><i class="fas fa-spinner fa-spin"></i> Loading...</p>
                    </div>

                    <!-- Input Group Effects -->
                    <div class="effects-section" id="effectsSection" style="display:none;">
                        <h5 class="section-title"><i class="fas fa-sliders-h"></i> Input Group Effects (EQ)</h5>
                        <div id="effectsContent"></div>
                    </div>

                    <!-- Presets -->
                    <div class="presets-section">
                        <h5 class="section-title"><i class="fas fa-bookmark"></i> Routing Presets</h5>
                        <div class="d-flex gap-2 mb-2">
                            <input type="text" id="presetName" class="form-control form-control-sm" style="max-width:250px;"
                                placeholder="Preset name...">
                            <button class="btn btn-outline-primary btn-sm" onclick="SavePreset()">
                                <i class="fas fa-save"></i> Save
                            </button>
                        </div>
                        <div class="preset-list" id="presetList">
                            <span class="text-muted">No presets saved.</span>
                        </div>
                    </div>
                </div>

            <?php } ?>
        </div>
    </div>

    <script>
        var matrixData = null;
        var isDirty = false;
        var volumeTimers = {};

        function LoadData() {
            $.getJSON('/api/pipewire/audio/routing', function (data) {
                matrixData = data;
                RenderMatrix();
                RenderEffects();
                isDirty = false;
                UpdateDirtyIndicator();
            }).fail(function () {
                $('#matrixContainer').html('<p class="text-danger">Failed to load routing data.</p>');
            });

            LoadPresets();
        }

        function RenderMatrix() {
            if (!matrixData || !matrixData.outputGroups || matrixData.outputGroups.length === 0) {
                $('#matrixContainer').html(
                    '<p class="text-muted text-center py-3">' +
                    '<i class="fas fa-info-circle"></i> No output groups configured. ' +
                    '<a href="pipewire-audio.php">Create output groups</a> first.</p>');
                return;
            }

            if (!matrixData.matrix || matrixData.matrix.length === 0) {
                $('#matrixContainer').html(
                    '<p class="text-muted text-center py-3">' +
                    '<i class="fas fa-info-circle"></i> No input groups configured. ' +
                    '<a href="pipewire-input-mixing.php">Create input groups</a> first.</p>');
                return;
            }

            var enabledOGs = matrixData.outputGroups.filter(function (og) { return og.enabled; });

            var html = '<table class="routing-matrix">';
            html += '<thead><tr><th></th>';
            enabledOGs.forEach(function (og) {
                html += '<th>' + EscapeHtml(og.name || 'Group ' + og.id) + '</th>';
            });
            html += '</tr></thead><tbody>';

            matrixData.matrix.forEach(function (row, ri) {
                html += '<tr>';
                html += '<td class="ig-label">';
                html += '<span>' + EscapeHtml(row.inputGroupName) + '</span>';
                if (row.hasEffects) {
                    html += ' <span class="badge bg-info" title="EQ active"><i class="fas fa-sliders-h"></i></span>';
                }
                html += '</td>';

                enabledOGs.forEach(function (og) {
                    var path = null;
                    row.paths.forEach(function (p) {
                        if (p.outputGroupId === og.id) path = p;
                    });

                    var connected = path ? path.connected : false;
                    var volume = path ? path.volume : 100;
                    var muted = path ? path.mute : false;

                    var cellClass = 'routing-cell';
                    if (muted) cellClass += ' muted';
                    if (!connected) cellClass += ' disconnected';

                    html += '<td class="' + cellClass + '" data-ig="' + row.inputGroupId + '" data-og="' + og.id + '">';

                    // Connection checkbox
                    html += '<div class="form-check form-check-inline">';
                    html += '<input class="form-check-input" type="checkbox" ' + (connected ? 'checked' : '') +
                        ' onchange="ToggleRoute(' + row.inputGroupId + ',' + og.id + ',this.checked)">';
                    html += '</div>';

                    // Volume control (visible only when connected)
                    html += '<div class="volume-control">';
                    html += '<button class="btn btn-sm btn-mute ' + (muted ? 'btn-danger' : 'btn-outline-secondary') + '" ' +
                        'onclick="ToggleMute(' + row.inputGroupId + ',' + og.id + ')" title="Mute">' +
                        '<i class="fas fa-volume-' + (muted ? 'mute' : 'up') + '"></i></button>';
                    html += '<input type="range" class="form-range volume-slider" min="0" max="100" value="' + volume + '" ' +
                        'oninput="AdjustRouteVolume(' + row.inputGroupId + ',' + og.id + ',this.value)">';
                    html += '<span class="volume-value">' + volume + '%</span>';
                    html += '</div>';

                    html += '</td>';
                });

                html += '</tr>';
            });

            html += '</tbody></table>';
            $('#matrixContainer').html(html);
        }

        function RenderEffects() {
            if (!matrixData || !matrixData.matrix || matrixData.matrix.length === 0) {
                $('#effectsSection').hide();
                return;
            }

            $('#effectsSection').show();
            var html = '';

            matrixData.matrix.forEach(function (row) {
                var igId = row.inputGroupId;
                html += '<div class="mb-3">';
                html += '<div class="d-flex align-items-center gap-2 mb-2">';
                html += '<strong>' + EscapeHtml(row.inputGroupName) + '</strong>';
                html += '<div class="form-check form-switch">';
                html += '<input class="form-check-input" type="checkbox" id="eqEnable_' + igId + '" ' +
                    (row.hasEffects ? 'checked' : '') +
                    ' onchange="ToggleEffects(' + igId + ',this.checked)">';
                html += '<label class="form-check-label" for="eqEnable_' + igId + '">EQ</label>';
                html += '</div>';
                html += '<button class="btn btn-sm btn-outline-secondary" onclick="AddEQBand(' + igId + ')"><i class="fas fa-plus"></i> Band</button>';
                html += '</div>';

                html += '<div id="eqBands_' + igId + '">';
                // Load existing bands from config
                var bands = GetIGEffectBands(igId);
                if (bands.length > 0) {
                    bands.forEach(function (band, bi) {
                        html += RenderEQBand(igId, bi, band);
                    });
                } else if (row.hasEffects) {
                    html += '<span class="text-muted" style="font-size:0.85rem;">EQ enabled but no bands configured.</span>';
                } else {
                    html += '<span class="text-muted" style="font-size:0.85rem;">No EQ bands. Enable EQ and click "+ Band" to add.</span>';
                }
                html += '</div>';
                html += '</div>';
            });

            $('#effectsContent').html(html);
        }

        function GetIGEffectBands(igId) {
            var bands = [];
            if (matrixData && matrixData.matrix) {
                matrixData.matrix.forEach(function (row) {
                    if (row.inputGroupId === igId && row.effects && row.effects.eq && row.effects.eq.bands) {
                        bands = row.effects.eq.bands;
                    }
                });
            }
            return bands;
        }

        function RenderEQBand(igId, bandIdx, band) {
            var freq = band.freq || 1000;
            var gain = band.gain || 0;
            var q = band.q || 1.4;
            var type = band.type || 'bq_peaking';

            var html = '<div class="eq-band" id="eqBand_' + igId + '_' + bandIdx + '">';
            html += '<select class="form-select form-select-sm" style="width:auto;" ' +
                'onchange="UpdateEQBand(' + igId + ',' + bandIdx + ',\'type\',this.value)">';
            html += '<option value="bq_peaking"' + (type === 'bq_peaking' ? ' selected' : '') + '>Peaking</option>';
            html += '<option value="bq_lowshelf"' + (type === 'bq_lowshelf' ? ' selected' : '') + '>Low Shelf</option>';
            html += '<option value="bq_highshelf"' + (type === 'bq_highshelf' ? ' selected' : '') + '>High Shelf</option>';
            html += '<option value="bq_lowpass"' + (type === 'bq_lowpass' ? ' selected' : '') + '>Low Pass</option>';
            html += '<option value="bq_highpass"' + (type === 'bq_highpass' ? ' selected' : '') + '>High Pass</option>';
            html += '</select>';
            html += '<label class="ms-2" style="font-size:0.8rem;">Freq:</label>';
            html += '<input type="number" class="form-control form-control-sm" value="' + freq + '" min="20" max="20000" step="1" ' +
                'onchange="UpdateEQBand(' + igId + ',' + bandIdx + ',\'freq\',this.value)">';
            html += '<label style="font-size:0.8rem;">Gain:</label>';
            html += '<input type="range" min="-15" max="15" step="0.5" value="' + gain + '" ' +
                'oninput="UpdateEQBandGain(' + igId + ',' + bandIdx + ',this.value)">';
            html += '<span class="gain-value" id="gainVal_' + igId + '_' + bandIdx + '">' + gain + ' dB</span>';
            html += '<label style="font-size:0.8rem;">Q:</label>';
            html += '<input type="number" class="form-control form-control-sm" value="' + q + '" min="0.1" max="10" step="0.1" ' +
                'onchange="UpdateEQBand(' + igId + ',' + bandIdx + ',\'q\',this.value)">';
            html += '<button class="btn btn-sm btn-outline-danger" onclick="RemoveEQBand(' + igId + ',' + bandIdx + ')">' +
                '<i class="fas fa-times"></i></button>';
            html += '</div>';
            return html;
        }

        // Route management
        function ToggleRoute(igId, ogId, connected) {
            matrixData.matrix.forEach(function (row) {
                if (row.inputGroupId === igId) {
                    row.paths.forEach(function (p) {
                        if (p.outputGroupId === ogId) {
                            p.connected = connected;
                        }
                    });
                    // Ensure path exists
                    var found = false;
                    row.paths.forEach(function (p) { if (p.outputGroupId === ogId) found = true; });
                    if (!found) {
                        row.paths.push({ outputGroupId: ogId, connected: connected, volume: 100, mute: false });
                    }
                }
            });
            MarkDirty();
            RenderMatrix();
        }

        function ToggleMute(igId, ogId) {
            matrixData.matrix.forEach(function (row) {
                if (row.inputGroupId === igId) {
                    row.paths.forEach(function (p) {
                        if (p.outputGroupId === ogId) {
                            p.mute = !p.mute;
                        }
                    });
                }
            });
            MarkDirty();
            RenderMatrix();
        }

        function AdjustRouteVolume(igId, ogId, volume) {
            volume = parseInt(volume);
            // Update local data
            matrixData.matrix.forEach(function (row) {
                if (row.inputGroupId === igId) {
                    row.paths.forEach(function (p) {
                        if (p.outputGroupId === ogId) {
                            p.volume = volume;
                        }
                    });
                }
            });

            // Update display
            var cell = $('td[data-ig="' + igId + '"][data-og="' + ogId + '"]');
            cell.find('.volume-value').text(volume + '%');

            // Debounced real-time update
            var key = igId + '_' + ogId;
            if (volumeTimers[key]) clearTimeout(volumeTimers[key]);
            volumeTimers[key] = setTimeout(function () {
                $.ajax({
                    url: '/api/pipewire/audio/routing/volume',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({ inputGroupId: igId, outputGroupId: ogId, volume: volume })
                });
            }, 150);

            MarkDirty();
        }

        // Effects management
        function ToggleEffects(igId, enabled) {
            // Save effects toggle — needs Apply to take effect
            var effects = { eq: { enabled: enabled, bands: [] } };
            if (enabled) {
                // Add default 3-band EQ
                effects.eq.bands = [
                    { type: 'bq_peaking', freq: 100, gain: 0, q: 1.4 },
                    { type: 'bq_peaking', freq: 1000, gain: 0, q: 1.4 },
                    { type: 'bq_peaking', freq: 10000, gain: 0, q: 1.4 }
                ];
            }

            $.ajax({
                url: '/api/pipewire/audio/input-groups/effects',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ groupId: igId, effects: effects }),
                success: function () {
                    MarkDirty();
                    LoadData(); // Reload to show bands
                }
            });
        }

        function AddEQBand(igId) {
            // Load current config, add band, save
            $.getJSON('/api/pipewire/audio/input-groups', function (data) {
                var ig = null;
                (data.inputGroups || []).forEach(function (g) { if (g.id === igId) ig = g; });
                if (!ig) return;

                if (!ig.effects) ig.effects = { eq: { enabled: true, bands: [] } };
                if (!ig.effects.eq) ig.effects.eq = { enabled: true, bands: [] };
                if (!ig.effects.eq.bands) ig.effects.eq.bands = [];
                ig.effects.eq.enabled = true;
                ig.effects.eq.bands.push({ type: 'bq_peaking', freq: 1000, gain: 0, q: 1.4 });

                $.ajax({
                    url: '/api/pipewire/audio/input-groups/effects',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({ groupId: igId, effects: ig.effects }),
                    success: function () { MarkDirty(); LoadData(); }
                });
            });
        }

        function RemoveEQBand(igId, bandIdx) {
            $.getJSON('/api/pipewire/audio/input-groups', function (data) {
                var ig = null;
                (data.inputGroups || []).forEach(function (g) { if (g.id === igId) ig = g; });
                if (!ig || !ig.effects || !ig.effects.eq || !ig.effects.eq.bands) return;

                ig.effects.eq.bands.splice(bandIdx, 1);
                if (ig.effects.eq.bands.length === 0) ig.effects.eq.enabled = false;

                $.ajax({
                    url: '/api/pipewire/audio/input-groups/effects',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({ groupId: igId, effects: ig.effects }),
                    success: function () { MarkDirty(); LoadData(); }
                });
            });
        }

        function UpdateEQBand(igId, bandIdx, field, value) {
            if (field === 'freq' || field === 'q') value = parseFloat(value);

            // Real-time update via PipeWire
            var body = { groupId: igId, band: bandIdx };
            body[field] = value;
            $.ajax({
                url: '/api/pipewire/audio/input-groups/eq/update',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(body)
            });
        }

        function UpdateEQBandGain(igId, bandIdx, value) {
            value = parseFloat(value);
            $('#gainVal_' + igId + '_' + bandIdx).text(value + ' dB');
            UpdateEQBand(igId, bandIdx, 'gain', value);
        }

        // Presets
        function LoadPresets() {
            $.getJSON('/api/pipewire/audio/routing/presets', function (data) {
                var presets = data.presets || [];
                if (presets.length === 0) {
                    $('#presetList').html('<span class="text-muted">No presets saved.</span>');
                    return;
                }

                var html = '';
                presets.forEach(function (p) {
                    html += '<div class="preset-item">';
                    html += '<div>';
                    html += '<strong>' + EscapeHtml(p.name) + '</strong>';
                    if (p.description) html += ' <small class="text-muted">' + EscapeHtml(p.description) + '</small>';
                    html += ' <small class="text-muted">(' + p.created + ')</small>';
                    html += '</div>';
                    html += '<div class="d-flex gap-1">';
                    html += '<button class="btn btn-sm btn-outline-primary" onclick="LoadPreset(\'' +
                        EscapeHtml(p.name) + '\')"><i class="fas fa-upload"></i> Load</button>';
                    html += '<button class="btn btn-sm btn-outline-danger" onclick="DeletePreset(\'' +
                        EscapeHtml(p.name) + '\')"><i class="fas fa-trash"></i></button>';
                    html += '</div>';
                    html += '</div>';
                });
                $('#presetList').html(html);
            });
        }

        function SavePreset() {
            var name = $('#presetName').val().trim();
            if (!name) {
                alert('Enter a preset name.');
                return;
            }

            $.ajax({
                url: '/api/pipewire/audio/routing/presets',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ name: name }),
                success: function (data) {
                    $('#presetName').val('');
                    LoadPresets();
                    $.jGrowl('Preset "' + name + '" saved.', { themeState: 'success' });
                },
                error: function () {
                    $.jGrowl('Failed to save preset.', { themeState: 'danger' });
                }
            });
        }

        function LoadPreset(name) {
            if (!confirm('Load preset "' + name + '"? This will overwrite current routing.')) return;

            $.ajax({
                url: '/api/pipewire/audio/routing/presets/load',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ name: name }),
                success: function (data) {
                    LoadData();
                    MarkDirty();
                    $.jGrowl(data.message || 'Preset loaded.', { themeState: 'success' });
                },
                error: function () {
                    $.jGrowl('Failed to load preset.', { themeState: 'danger' });
                }
            });
        }

        function DeletePreset(name) {
            if (!confirm('Delete preset "' + name + '"?')) return;

            $.ajax({
                url: '/api/pipewire/audio/routing/presets/' + encodeURIComponent(name),
                method: 'DELETE',
                success: function () {
                    LoadPresets();
                    $.jGrowl('Preset deleted.', { themeState: 'success' });
                }
            });
        }

        // Save & Apply
        function SaveAndApply() {
            if (!matrixData || !matrixData.matrix) return;

            // Build routes array from matrix
            var routes = [];
            matrixData.matrix.forEach(function (row) {
                row.paths.forEach(function (p) {
                    routes.push({
                        inputGroupId: row.inputGroupId,
                        outputGroupId: p.outputGroupId,
                        connected: p.connected,
                        volume: p.volume,
                        mute: p.mute
                    });
                });
            });

            // Save routing
            $.ajax({
                url: '/api/pipewire/audio/routing',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ routes: routes }),
                success: function () {
                    // Apply input groups config (regenerates PipeWire config)
                    $.ajax({
                        url: '/api/pipewire/audio/input-groups/apply',
                        method: 'POST',
                        success: function (data) {
                            isDirty = false;
                            UpdateDirtyIndicator();
                            $.jGrowl(data.message || 'Routing applied.', { themeState: 'success' });
                            setTimeout(LoadData, 1000);
                        },
                        error: function () {
                            $.jGrowl('Failed to apply routing.', { themeState: 'danger' });
                        }
                    });
                },
                error: function () {
                    $.jGrowl('Failed to save routing.', { themeState: 'danger' });
                }
            });
        }

        // Helpers
        function MarkDirty() {
            isDirty = true;
            UpdateDirtyIndicator();
        }

        function UpdateDirtyIndicator() {
            if (isDirty) {
                $('#dirtyIndicator').addClass('show');
            } else {
                $('#dirtyIndicator').removeClass('show');
            }
        }

        function EscapeHtml(str) {
            if (!str) return '';
            return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        // Init
        $(document).ready(function () {
            LoadData();
        });
    </script>

    <?php if (!$modalMode) { ?>
        <?php include 'common/footer.inc'; ?>
    <?php } ?>
</body>

</html>
