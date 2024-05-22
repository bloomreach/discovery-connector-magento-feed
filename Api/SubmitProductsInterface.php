<?php

namespace Bloomreach\Feed\Api;

interface SubmitProductsInterface
{
    const FEED_PATH = 'bloomreach_feed/';

    /**
     * POST
     * Triggers a complete product feed submission to the bloomreach network.
     *
     * @api
     *
     * @return array
     */
    public function execute();

    /**
     * GET
     * Returns the status of the last or current product feed submission.
     *
     * @api
     *
     * @return array
     */
    public function getStatus();
}
