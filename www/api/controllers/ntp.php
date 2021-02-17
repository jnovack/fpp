<?


/////////////////////////////////////////////////////////////////////////////
// GET /api/ntp/query
function GetNTPTime() {

    $bit_max = 4294967296;
    $epoch_convert = 2208988800;
    $vn = 3;

    $servers = array('0.pool.ntp.org','1.pool.ntp.org','2.pool.ntp.org','3.pool.ntp.org');
    $server_count = count($servers);

    $header = '00';
    $header .= sprintf('%03d',decbin($vn));
    $header .= '011';

    $request_packet = chr(bindec($header));

    $i = 0;
    for($i; $i < $server_count; $i++) {
        $socket = @fsockopen('udp://'.$servers[$i], 123, $err_no, $err_str,1);
        if ($socket) {

            for ($j=1; $j<40; $j++) {
                $request_packet .= chr(0x0);
            }

            $local_sent_explode = explode(' ',microtime());
            $local_sent = $local_sent_explode[1] + $local_sent_explode[0];

            $originate_seconds = $local_sent_explode[1] + $epoch_convert;

            $originate_fractional = round($local_sent_explode[0] * $bit_max);

            $originate_fractional = sprintf('%010d',$originate_fractional);

            $packed_seconds = pack('N', $originate_seconds);
            $packed_fractional = pack("N", $originate_fractional);

            $request_packet .= $packed_seconds;
            $request_packet .= $packed_fractional;

            if (fwrite($socket, $request_packet)) {
                $data = NULL;
                stream_set_timeout($socket, 1);

                $response = fread($socket, 48);

                $local_received = microtime(true);
            }
            fclose($socket);

            if (strlen($response) == 48) {
                break;
            } else {
                if ($i == $server_count-1) {
                    //this was the last server on the list, so give up
                    return json(array(
                        'meta' => array('success' => 'true', 'error' => 'unable to establish a connection'),
                        'data' => array()
                    ));
                }
            }
        } else {
            if ($i == $server_count-1) {
            //this was the last server on the list, so give up
            return json(array(
                'meta' => array('success' => 'true', 'error' => 'unable to establish a connection'),
                'data' => array()
            ));
            }
        }
    }

    $unpack0 = unpack("N12", $response);

    $remote_originate_seconds = sprintf('%u', $unpack0[7])-$epoch_convert;
    $remote_received_seconds = sprintf('%u', $unpack0[9])-$epoch_convert;
    $remote_transmitted_seconds = sprintf('%u', $unpack0[11])-$epoch_convert;

    $remote_originate_fraction = sprintf('%u', $unpack0[8]) / $bit_max;
    $remote_received_fraction = sprintf('%u', $unpack0[10]) / $bit_max;
    $remote_transmitted_fraction = sprintf('%u', $unpack0[12]) / $bit_max;

    $remote_originate = $remote_originate_seconds + $remote_originate_fraction;
    $remote_received = $remote_received_seconds + $remote_received_fraction;
    $remote_transmitted = $remote_transmitted_seconds + $remote_transmitted_fraction;

    $unpack1 = unpack("C12", $response);

    $header_response =  base_convert($unpack1[1], 10, 2);
    $header_response = sprintf('%08d',$header_response);

    $mode_response = bindec(substr($header_response, -3));
    $vn_response = bindec(substr($header_response, -6, 3));
    $stratum_response =  base_convert($unpack1[2], 10, 2);
    $stratum_response = bindec($stratum_response);
    $delay = (($local_received - $local_sent) / 2)  - ($remote_transmitted - $remote_received);

    $server = $servers[$i];

    $ntp_time =  $remote_transmitted - $delay;
    $ntp_time_explode = explode('.',$ntp_time);

    $ntp_time_formatted = date('Y-m-d H:i:s', $ntp_time_explode[0]).'.'.$ntp_time_explode[1];

    $server_time =  microtime();
    $server_time_explode = explode(' ', $server_time);
    $server_time_micro = round($server_time_explode[0],4);

    $server_time_formatted = date('Y-m-d H:i:s', time()) .'.'. substr($server_time_micro,2);

    return json(array(
        'meta' => array('success' => 'true'),
        'data' => array(
        'server' => $server,
        'version' => $vn_response,
        'mode' => $mode_response,
        'stratum' => $stratum_response,
        'origin_time' => $remote_originate,
        'received' => $remote_received,
        'delay' => round($delay * 1000),
        'ntptime' => $ntp_time_explode[0],
        'localtime' => $server_time_formatted,
        'remotetime' => $ntp_time_formatted,
        'offset' => $remote_received - $remote_originate,
    )));
}

?>
