<?php

namespace Hooks\Tools;

use GuzzleHttp\Client;
use Hooks\Tools\ConfigTools;

/**
 * Class ServiceTools
 *
 * @package Hooks\Tools
 */
class ServiceTools
{
    /**
     * @param string $repository  Repository
     * @param string $SHA         SHA
     * @param string $state       State (pending, success, error, or failure).
     * @param string $targetUrl   Target URL
     * @param string $description Description
     */
    public static function sendGitHubStatus($repository, $SHA, $state, $targetUrl=null, $description=null)
    {
        $config = ConfigTools::getLocalConfig(['github' => ['token' => null]]);
        $client = new Client();

        $response = $client->post('https://api.github.com/repos/' . $repository . '/statuses/' . $SHA, [
            'body' => [
                'state' => $state,
                'target_url' => $targetUrl,
                'description' => $description,
            ],
            'headers' => [
                'Authorization' => 'token ' . $config['github']['token'],
            ],
        ]);
    }
}