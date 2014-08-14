<?php

/******
 *
 * NOTE:
 *
 * You don't need to modify this file for the CodeLab. Please don't modify it.
 *
 ******/


/**
 * Provides API data.
 */
final class ApiServer {

    public function __construct() {
    }

    /**
     * Returns some shopping cart data for a certain user.
     *
     * @param $requestData array PHP GET parameters, i.e. $_GET
     * @return array the contents of a certain user's shopping cart
     */
    public function handleDataRequest($requestData) {
        // Only support one user.
        if ($requestData['userid'] != 1234) {
            throw new RuntimeException("Unexpected user: {$requestData['userid']}");
        }
        return [
            [
                "item" => "Plush lobster",
                "quantity" => "1",
            ],
            [
                "item" => "Plush lobster food",
                "quantity" => "10",
            ]
        ];
    }
}
