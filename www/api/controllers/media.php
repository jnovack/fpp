<?

// This path is relative to the api directory, not the controllers directory
require_once '../common/metadata.php';

/**
 * List all media files
 *
 * Returns a list of media files (includes both music and video files).
 *
 * @route GET /api/media
 * @response ["Frosty.mp4", "Jingle_Bells.mp3"]
 */
function GetMedia()
{
    global $settings;
    $files = array();

    // Use scandir instead of glob for better performance
    $dirs = [$settings['musicDirectory'], $settings['videoDirectory']];
    foreach ($dirs as $dir) {
        if (is_dir($dir)) {
            foreach (scandir($dir) as $file) {
                if ($file !== '.' && $file !== '..' && is_file($dir . '/' . $file)) {
                    $files[] = $file;
                }
            }
        }
    }

    sort($files);

    return json($files);
}

/**
 * Get duration of media item
 *
 * Returns the duration of a media item.
 *
 * @route GET /api/media/{MediaName}/duration
 * @response {"1min_720p29_2014-10-01.mp4": {"duration": 60.010666666667}}
 * @response 404 "Not found: {MediaName}"
 */
function GetMediaDuration()
{
    global $settings;
    $resp = array();

    $file = params('MediaName');

    $resp = getMediaDurationInfo($file, true);

    if ($resp[$file]['duration'] < 0) {
        halt(404, "Not found: " . $file);
    } else {
        return json($resp);
    }

}

/**
 * Get metadata for media item
 *
 * Returns metadata streams, codecs, profiles, type for a specific media file.
 *
 * @route GET /api/media/{MediaName}/meta
 * @response {"programs": [], "streams": [{"index": 0, "codec_name": "h264", "codec_long_name": "H.264 / AVC / MPEG-4 AVC / MPEG-4 part 10", "profile": "High", "codec_type": "video", "codec_time_base": "500/29971"}]}
 */
function GetMediaMetaData()
{
    global $settings;
    $resp = array();

    $file = params('MediaName');

    $resp = GetMetaDataFromFFProbe($file);

    return json($resp);
}
