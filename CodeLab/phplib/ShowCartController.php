<?php

require_once 'ApiClient.php';

final class ShowCartController {

    /**
     * Renders the contents of a user's cart.
     */
    public function showCart() {
        $client = new ApiClient();
        $cartData = $client->getData();
        echo <<<HTML
<html>
<head><title>Your Cart</title></head>
<body>

<table>

    <tr><th>Item name</th><th>Quantity</th></tr>

    <tr>
        <td>{$cartData[0]["item"]}</td>
        <td>{$cartData[0]["quantity"]}</td>
    </tr>

    <tr>
        <td>{$cartData[1]["item"]}</td>
        <td>{$cartData[1]["quantity"]}</td>
    </tr>

</table>

</html>
HTML;
    }
}
