<?php
namespace App\Controller\Frontend;

use App\Radio\Backend\Liquidsoap;
use App\Entity;
use App\Http\Request;
use App\Http\Response;
use Psr\Http\Message\ResponseInterface;

class PublicController
{
    public function indexAction(Request $request, Response $response, $station_id = null, $autoplay = false): ResponseInterface
    {
        $template_vars = ['autoplay' => ($autoplay === 'autoplay')];
        return $this->_getPublicPage($request, $response, 'frontend/public/index', $template_vars);
    }

    public function embedAction(Request $request, Response $response, $station_id = null, $autoplay = false): ResponseInterface
    {
        $template_vars = ['autoplay' => ($autoplay === 'autoplay')];
        return $this->_getPublicPage($request, $response, 'frontend/public/embed', $template_vars);
    }

    public function embedrequestsAction(Request $request, Response $response): ResponseInterface
    {
        return $this->_getPublicPage($request, $response, 'frontend/public/embedrequests');
    }

    protected function _getPublicPage(Request $request, Response $response, $template_name, $template_vars = [])
    {
        // Override system-wide iframe refusal
        $response = $response->withoutHeader('X-Frame-Options');

        $station = $request->getStation();

        if (!$station->getEnablePublicPage()) {
            throw new \App\Exception\StationNotFound;
        }

        $frontend = $request->getStationFrontend();

        // List all publicly available streams.
        $current_stream = null;
        $streams = [];
        foreach ($station->getMounts() as $mount) {
            /** @var Entity\StationMount $mount */
            if ($mount->isVisibleOnPublicPages()) {
                $stream = [
                    'name' => $mount->getDisplayName(),
                    'url'  => (string)$frontend->getUrlForMount($station, $mount),
                ];

                $streams[] = $stream;
                if ($mount->getIsDefault()) {
                    $current_stream = $stream;
                }
            }
        }

        $remotes = $request->getStationRemotes();
        foreach($remotes as $ra_proxy) {
            $remote = $ra_proxy->getRemote();

            if ($remote->isVisibleOnPublicPages()) {
                $streams[] = [
                    'name'  => $remote->getDisplayName(),
                    'url'   => (string)$ra_proxy->getAdapter()->getPublicUrl($remote)
                ];
            }
        }

        if (0 === count($streams)) {
            throw new \App\Exception\StationUnsupported;
        }

        if (null === $current_stream) {
            $current_stream = reset($streams);
        }

        return $request->getView()->renderToResponse($response, $template_name, $template_vars + [
            'station' => $station,
            'streams' => $streams,
            'current_stream' => $current_stream,
        ]);
    }

    public function playlistAction(Request $request, Response $response, $station_id, $format = 'pls'): ResponseInterface
    {
        $station = $request->getStation();
        $fa = $request->getStationFrontend();

        $stream_urls = $fa->getStreamUrls($station);

        $format = strtolower($format);

        switch ($format) {
            // M3U Playlist Format
            case "m3u":
                $m3u_file = implode("\n", $stream_urls);

                return $response
                    ->withHeader('Content-Type', 'audio/x-mpegurl')
                    ->withHeader('Content-Disposition', 'attachment; filename=' . $station->getShortName() . '.m3u')
                    ->write($m3u_file);
                break;

            // PLS Playlist Format
            case "pls":
            default:
                $output = [
                    '[playlist]'
                ];

                $i = 1;
                foreach ($stream_urls as $stream_url) {
                    $output[] = 'File' . $i . '=' . $stream_url;
                    $output[] = 'Length' . $i . '=-1';
                    $i++;
                }

                $output[] = '';
                $output[] = 'NumberOfEntries=' . count($stream_urls);
                $output[] = 'Version=2';

                return $response
                    ->withHeader('Content-Type', 'audio/x-scpls')
                    ->withHeader('Content-Disposition', 'attachment; filename=' . $station->getShortName() . '.pls')
                    ->write(implode("\n", $output));
                break;
        }
    }

    public function djAction(Request $request, Response $response, $station_id, $format = 'pls'): ResponseInterface
    {
        $station = $request->getStation();

        if (!$station->getEnablePublicPage()) {
            throw new \App\Exception\StationNotFound;
        }

        if (!$station->getEnableStreamers()) {
            throw new \App\Exception\StationUnsupported;
        }

        $backend = $request->getStationBackend();

        if (!($backend instanceof Liquidsoap)) {
            throw new \App\Exception\StationUnsupported;
        }

        $wss_url = (string)$backend->getWebStreamingUrl($station, $request->getRouter()->getBaseUrl());
        $wss_url = str_replace('wss://', '', $wss_url);

        return $request->getView()->renderToResponse($response, 'frontend/public/dj', [
            'station' => $station,
            'base_uri' => $wss_url,
        ]);
    }
}
