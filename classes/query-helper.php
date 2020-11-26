<?php
/**
 * MIT License
 *
 * Copyright (c) 2019 OnPay.io
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */
class wc_onpay_query_helper {
    /**
     * Generates URL for current page with params
     * @param $params
     * @return string
     */
    public static function generate_url($params) {
        if (is_ssl()) {
            $currentPage = 'https://';
        } else {
            $currentPage = 'http://';
        }
        $currentPage .= $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $baseUrl = explode('?', $currentPage, 2);
        $baseUrl = array_shift($baseUrl);
        $fullUrl = $baseUrl . '?' . http_build_query($params);
        return $fullUrl;
    }

    /**
     * Since Wordpress not really allows getting custom queries, we'll implement this method allowing us to get the values we need.
     * @param string $query
     * @return string|null
     */
    public static function get_query_value($query) {
        if (isset($_GET[$query])) {
            return sanitize_text_field($_GET[$query]);
        }
        return null;
    }

    /**
     * Get all query values sanitized in array.
     * @return array
     */
    public static function get_query() {
        $query = [];
        foreach ($_GET as $key => $get) {
            $query[$key] = sanitize_text_field($get);
        }
        return $query;
    }

    /**
     * Since Wordpress not really allows getting post values, we have this method for easier access.
     * @param string $key
     * @return string|null
     */
    public static function get_post_value($key) {
        if (isset($_POST[$key])) {
            return sanitize_text_field($_POST[$key]);
        }
        return null;
    }
}