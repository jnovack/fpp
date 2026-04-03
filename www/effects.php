<!DOCTYPE html>
<html lang="en">

<head>
  <?php
  require_once('config.php');
  include 'common/htmlMeta.inc';

  if (file_exists(__DIR__ . "/fppdefines.php")) {
    include_once __DIR__ . '/fppdefines.php';
  } else {
    include_once __DIR__ . '/fppdefines_unknown.php';
  }

  require_once "common.php";
  include 'common/menuHead.inc';
  ?>

  <link rel="stylesheet" href="bootstrap-table/css/bootstrap-table.min.css">
  <link rel="stylesheet" href="bootstrap-table/extensions/bootstrap-table-filter-control.min.css">
  <script src="bootstrap-table/js/bootstrap-table.min.js"></script>
  <script src="bootstrap-table/extensions/bootstrap-table-filter-control.min.js"></script>

  <style>
    #tblEffectLibrary thead th {
      background-color: #d9d9d9;
    }

    #tblEffectLibrary thead th:first-child {
      border-top-left-radius: 8px;
    }

    #tblEffectLibrary thead th:last-child {
      border-top-right-radius: 8px;
    }

    #tblEffectLibrary thead th .both {
      background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 512" fill="%23888"><path d="m103.05877,41.4c9.37707,-12.5 24.60541,-12.5 33.98248,0l96.02113,128c6.90152,9.2 8.92696,22.9 5.17614,34.9s-12.45274,19.8 -22.20489,19.8l-192.04225,-0.1c-9.67713,0 -18.45406,-7.8 -22.20489,-19.8s-1.65036,-25.7 5.17614,-34.9l96.02113,-128l0.07501,0.1zm0,429.3l-96.02113,-128c-6.90152,-9.2 -8.92696,-22.9 -5.17614,-34.9s12.45274,-19.8 22.20489,-19.8l192.04225,0c9.67713,0 18.45406,7.8 22.20489,19.8s1.65036,25.7 -5.17614,34.9l-96.02113,128c-9.37707,12.5 -24.60541,12.5 -33.98248,0l-0.07501,0z"/></svg>');
    }

    #tblEffectLibrary thead th .asc {
      background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 512" fill="%230d47a1"><path d="m136.9496,41.4c-9.3763,-12.5 -24.60342,-12.5 -33.97972,0l-96.01334,128c-6.90096,9.2 -8.92624,22.9 -5.17572,34.9s12.45173,19.8 22.20309,19.8l192.02668,0c9.67634,0 18.45256,-7.8 22.20309,-19.8s1.65023,-25.7 -5.17572,-34.9l-96.01334,-128l-0.07501,0z"/></svg>');
    }

    #tblEffectLibrary thead th .desc {
      background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 512" fill="%230d47a1"><path d="m136.94959,471.6c-9.3763,12.5 -24.60342,12.5 -33.97972,0l-96.01334,-128c-6.90096,-9.2 -8.92624,-22.9 -5.17572,-34.9s12.45173,-19.8 22.20308,-19.8l192.02667,0c9.67634,0 18.45256,7.8 22.20308,19.8s1.65023,25.7 -5.17572,34.9l-96.01334,128l-0.07501,0z"/></svg>');
    }
  </style>


  <script>
    var EffectSelectedName = "";
    var EffectSelectedType = "";
    var RunningEffectSelectedId = -1;
    var RunningEffectSelectedName = "";

    $(function () {
      $('#tblEffectLibraryBody').on('click', '.buttons', function (event, ui) {
        $('#tblEffectLibraryBody tr').removeClass('effectSelectedEntry');
        var $selectedEntry = $(this).parent().parent();
        $selectedEntry.addClass('effectSelectedEntry');
        EffectSelectedName = $selectedEntry.find('td:first').text();
        EffectSelectedType = $selectedEntry.find('td:nth-child(2)').text();

        var body = "Loop Effect: <input type='checkbox' id='loopEffect'><br>Run in Background: <input type='checkbox' id='backgroundEffect'><br>Start Channel Override: ";
        body += "<input id='effectStartChannel' class='default-value' type='number' value='' min='1' max='<? echo FPPD_MAX_CHANNELS; ?>' />";

        DoModalDialog({
          id: "StartEffectDialog",
          title: 'Start Effect ' + $selectedEntry.find('td:first').text(),
          backdrop: true,
          keyboard: true,
          class: "modal-sm",
          body: body,
          buttons: {
            "Start": {
              click: function () {
                StartSelectedEffect();
                CloseModalDialog("StartEffectDialog");
              },
              class: 'btn-success'
            },
            "Cancel": function () { CloseModalDialog("StartEffectDialog"); },
          }
        });
      });

      $('#tblRunningEffectsBody').on('click', '.buttons', function (event, ui) {
        $('#tblRunningEffectsBody tr').removeClass('effectSelectedEntry');
        var $selectedEntry = $(this).parent().parent();
        $selectedEntry.addClass('effectSelectedEntry');
        RunningEffectSelectedId = $selectedEntry.find('td:first').text();
        RunningEffectSelectedName = $selectedEntry.find('td:nth-child(2)').text();
        StopEffect();
        console.log('stopping')
        //SetButtonState('#btnStopEffect','enable');
      });
    });

    $(document).on('click', '.stop-overlay-effects', function () {
      const model = $(this).data('model');
      SelectedOverlayModel = model;

      const url = 'api/command/' +
        encodeURIComponent('Overlay Model Effect') + '/' +
        encodeURIComponent(model) + '/' +
        'Enabled/' +
        encodeURIComponent('Stop Effects');

      const $btn = $(this).prop('disabled', true);

      $.get(url)
        .done(() => {
          $.jGrowl('Stopped all effects on ' + model, { themeState: 'success' });
          GetRunningOverlayEffects();
        })
        .fail(() => {
          DialogError('Error', 'Error stopping effects on ' + model);
          GetRunningOverlayEffects();
        });
    });


    function StartSelectedEffect() {
      var row = $('#tblEffectLibraryBody tr').find('.effectSelectedEntry');
      var startChannel = $('#effectStartChannel').val();
      var loop = 0;
      var background = 0;

      if (startChannel == undefined || startChannel == '')
        startChannel = 0;
      else
        startChannel = parseInt(startChannel);

      if ($('#loopEffect').is(':checked'))
        loop = 1;

      if ($('#backgroundEffect').is(':checked'))
        background = 1;

      var url = '';
      if (EffectSelectedType == 'eseq') {
        url = 'api/command/Effect Start/' + EffectSelectedName + '/' + startChannel + '/' + loop + '/' + background;
      } else {
        url = 'api/command/FSEQ Effect Start/' + EffectSelectedName + '/' + loop + '/' + background;
      }

      $.get(url
      ).done(function () {
        $.jGrowl('Effect Started', { themeState: 'success' });
        GetRunningEffects();
      }).fail(function () {
        DialogError('Error Starting Effect', 'Error Starting ' + name + ' Effect');
      });
    }

    function pageSpecific_PageLoad_DOM_Setup() {
      var $table = $('#tblEffectLibrary');

      $table.bootstrapTable({
        sortName: 'name',
        sortOrder: 'asc',
        filterControl: true,
        striped: true,
        showColumns: false,
        undefinedText: ''
      });

    }

  </script>


  <title><? echo $pageTitle; ?></title>
</head>

<body onLoad="GetRunningEffects(); GetRunningOverlayEffects();">
  <div id="bodyWrapper">
    <?php
    $activeParentMenuItem = 'status';
    include 'menu.inc';

    function PrintEffectRows()
    {
      $files = array();

      global $effectDirectory;
      foreach (scandir($effectDirectory) as $seqFile) {
        if ($seqFile != '.' && $seqFile != '..' && preg_match('/.eseq$/', $seqFile)) {
          $seqFile = preg_replace('/.eseq$/', '', $seqFile);
          $files[$seqFile] = "eseq";
        }
      }

      global $sequenceDirectory;
      foreach (scandir($sequenceDirectory) as $seqFile) {
        if ($seqFile != '.' && $seqFile != '..' && preg_match('/.fseq$/', $seqFile)) {
          $seqFile = preg_replace('/.fseq$/', '', $seqFile);
          $files[$seqFile] = "fseq";
        }
      }

      ksort($files);

      foreach ($files as $f => $t) {
        echo "<tr id='effect_" . $f . "'><td><img src='images/redesign/icon-" . $t . ".svg' alt=" . $t . " class='icon-effect-type'/>" . $f . "</td><td>" . $t . "</td><td><button class='buttons btn-success'>Start</button></td></tr>\n";
      }
    }

    ?>

    <div class="mainContainer">
      <h1 class="title">Effects</h1>
      <div class="pageContent">


        <div class="row">
          <div class="col">
            <h2>Effects Library</h2>
            <div id="divEffectLibrary">
              <div class='fppTableWrapper'>
                <div class='fppTableContents'>
                  <table id="tblEffectLibrary" class="fppActionTable" width="100%" cellpadding=1 cellspacing=0>
                    <thead>
                      <tr>
                        <th data-field="name" data-sortable="true" data-filter-control="input">Effect Name</th>
                        <th data-field="type" data-sortable="true" data-filter-control="input">Type</th>
                        <th data-field="action" width='15%'></th>
                      </tr>
                    </thead>
                    <tbody id='tblEffectLibraryBody'>
                      <? PrintEffectRows(); ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

          </div>
          <div class="col pin-parent">
            <div id="divRunningEffects" class="backdrop-disabled">
              <h2>Running Effects</h2>
              <!-- <input id="btnStopEffect" type="button" class="disableButtons" value="Stop Effect" onclick="StopEffect();" /><br> -->
              <div class='fppTableWrapper'>
                <div class='fppTableContents'>
                  <table id="tblRunningEffects" class="fppActionTable fppActionTable-success" width="100%" cellpadding=1
                    cellspacing=0>
                    <thead>
                      <tr>
                        <th>ID</th>
                        <th>Running Effects</th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody id='tblRunningEffectsBody'></tbody>
                  </table>
                  <div class="tblRunningEffectsPlaceholder">
                    There are currently no effects running
                  </div>
                </div>
              </div>
            </div>

            <div style="height: 20px;"></div>

            <div id="divOverlayEffects" class="divOverlayEffectsDisabled">
              <h2 id="overlayEffectsTitle">Overlay Model Effects</h2>
              <!-- <input id="btnStopEffect" type="button" class="disableButtons" value="Stop Effect" onclick="StopEffect();" /><br> -->
              <div class='fppTableWrapper'>
                <div class='fppTableContents'>
                  <table id="tblOverlayEffects" class="fppActionTable fppActionTable-success" width="100%" cellpadding=1
                    cellspacing=0>
                    <thead>
                      <tr>
                        <th>Model</th>
                        <th>Running Effects</th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody id='tblOverlayEffectsBody'></tbody>
                  </table>
                  <div class="tblOverlayEffectsPlaceholder">
                    There are currently no overlay model effects running
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <?php include 'common/footer.inc'; ?>
  </div>
</body>

</html>