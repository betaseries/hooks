<?php

namespace Hooks\Tools;

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

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/' . $repository . '/statuses/' . $SHA);
        curl_setopt($ch, CURLOPT_USERAGENT, 'betacie/hooks');
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: token ' . $config['github']['token']]);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'state' => $state,
            'target_url' => $targetUrl,
            'description' => $description,
            'context' => 'betacie/hooks',
        ]));

        $data = curl_exec($ch);
        curl_close($ch);
    }

    /**
     * @param string $repository Repository
     * @param string $SHA        SHA
     * @param array  $statuses   Statuses
     *
     * @return bool
     */
    public static function hasOnlyGreenGitHubStatuses($repository, $SHA, $statuses=[])
    {
        $config = ConfigTools::getLocalConfig(['github' => ['token' => null]]);

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/' . $repository . '/commits/' . $SHA . '/status');
        curl_setopt($ch, CURLOPT_USERAGENT, 'betacie/hooks');
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: token ' . $config['github']['token']]);

        $data = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($data, true);

        if (count($statuses) == 0) {
            foreach ($json['statuses'] as $status) {
                if ($status['context'] == 'betacie/hooks') {
                    continue;
                }
                if ($status['state'] !== 'success') {
                    return false;
                }
            }

            return true;
        } else {
            $greens = 0;
            foreach ($json['statuses'] as $status) {
                if ($status['state'] === 'success' && in_array($status['context'], $statuses)) {
                    $greens++;
                }
                if ($greens === count($statuses)) {
                    return true;
                }
            }

            return false;
        }
    }
}