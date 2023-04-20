<?php

namespace Bloomreach\Feed\Api;

interface SubmitProductsInterface
{
    /**
     * POST
     * Triggers a complete product feed submission to the bloomreach network.
     *
     * @api
     * @param string $username
     * @param string $password
     *
     * @return string
     */
    public function execute($username, $password);

    /**
     * GET
     * Returns the status of the last or current product feed submission.
     *
     * @api
     *
     * @return string
     */
    public function getStatus();
}
