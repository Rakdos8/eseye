<?php

/*
 * This file is part of SeAT
 *
 * Copyright (C) 2015 to 2022 Leon Jacobs
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

namespace Seat\Eseye\Containers;

use ArrayObject;
use Carbon\Carbon;

/**
 * Class EsiResponse.
 *
 * @package Seat\Eseye\Containers
 */
class EsiResponse extends ArrayObject
{

    /**
     * @var string
     */
    public $raw;

    /**
     * @var array
     */
    public $headers;

    /**
     * @var array
     */
    public $raw_headers;

    /**
     * @var int
     */
    public $error_limit;

    /**
     * @var int
     */
    public $pages;

    /**
     * @var array
     */
    protected $expires_at;

    /**
     * @var string
     */
    protected $response_code;

    /**
     * @var mixed
     */
    protected $error_message;

    /**
     * @var mixed
     */
    protected $optional_return;

    /**
     * @var bool
     */
    protected $cached_load = false;

    /**
     * EsiResponse constructor.
     *
     * @param  string  $data
     * @param  array  $headers
     * @param  string  $expires
     * @param  int  $response_code
     */
    public function __construct(
        string $data, array $headers, string $expires, int $response_code)
    {

        // set the raw data to the raw property
        $this->raw = $data;

        // Normalize and parse the response headers
        $this->parseHeaders($headers);

        // decode and create an object from the data
        $data = json_decode($data);

        // Ensure that the value for 'expires' is longer than
        // 2 characters. The shortest expected value is 'now'. If it
        // is not longer than 2 characters it might be empty.
        $this->expires_at = strlen($expires) > 2 ? $expires : 'now';
        $this->response_code = $response_code;

        if (is_object($data)) {

            // If there is an error, set that.
            if (property_exists($data, 'error'))
                $this->error_message = $data->error;

            // If there is an error description, set that.
            if (property_exists($data, 'error_description'))
                $this->error_message .= ': ' . $data->error_description;
        }

        // Run the parent constructor
        parent::__construct(is_array($data) ? (array) $data : (object) $data, ArrayObject::ARRAY_AS_PROPS);
    }

    /**
     * Parse an array of header key value pairs.
     *
     * Interesting header values such as X-Esi-Error-Limit-Remain
     * and X-Pages are automatically mapped to properties in this
     * object.
     *
     * @param  array  $headers
     */
    private function parseHeaders(array $headers)
    {

        // Set the raw headers as we got from the constructor.
        $this->raw_headers = $headers;

        // flatten the headers array so that values are not arrays themselves
        // but rather simple key value pairs.
        $headers = array_map(function ($value) {

            if (! is_array($value))
                return $value;

            return implode(';', $value);
        }, $headers);

        // Set the parsed headers.
        $this->headers = $headers;

        // Check for some header values that might be interesting
        // such as the current error limit and number of pages
        // available.
        $this->hasHeader('X-Esi-Error-Limit-Remain') ?
            $this->error_limit = (int) $this->getHeader('X-Esi-Error-Limit-Remain') : null;

        $this->hasHeader('X-Pages') ? $this->pages = (int) $this->getHeader('X-Pages') : null;
    }

    /**
     * A helper method when a key might not exist within the
     * response object.
     *
     * @param  string  $index
     * @return mixed
     */
    public function optional(string $index)
    {

        if (! $this->offsetExists($index))
            return null;

        return $this->$index;
    }

    /**
     * Determine if this containers data should be considered
     * expired.
     *
     * Expiry is calculated by taking the expiry time and comparing
     * that to the local time. Before comparison though, the local
     * time is converted to the timezone in which the expiry time
     * is recorded. The resultant local time is then checked to
     * ensure that the expiry is not less than local time.
     *
     * @return bool
     */
    public function expired(): bool
    {

        if ($this->expires()->lte(
            carbon()->now($this->expires()->timezoneName))
        )
            return true;

        return false;
    }

    /**
     * @return \Carbon\Carbon
     */
    public function expires(): Carbon
    {

        return carbon($this->expires_at);
    }

    /**
     * @return null|string
     */
    public function error()
    {

        return $this->error_message;
    }

    /**
     * @return int
     */
    public function getErrorCode(): int
    {

        return $this->response_code;
    }

    /**
     * @return bool
     */
    public function setIsCachedLoad(): bool
    {

        return $this->cached_load = true;
    }

    /**
     * @return bool
     */
    public function isCachedLoad(): bool
    {

        return $this->cached_load;
    }

    /**
     * @param  string  $name
     * @return bool
     */
    public function hasHeader(string $name)
    {
        // turn headers into case insensitive array
        $key_map = array_change_key_case($this->headers, CASE_LOWER);

        // track for the requested header name
        return array_key_exists(strtolower($name), $key_map);
    }

    /**
     * @param  string  $name
     * @return mixed|null
     */
    public function getHeader(string $name)
    {
        // turn header name into case insensitive
        $insensitive_key = strtolower($name);

        // turn headers into case insensitive array
        $key_map = array_change_key_case($this->headers, CASE_LOWER);

        // track for the requested header name and return its value if exists
        if (array_key_exists($insensitive_key, $key_map))
            return $key_map[$insensitive_key];

        return null;
    }

    /**
     * @param  \Carbon\Carbon  $date
     */
    public function setExpires(Carbon $date)
    {
        // turn headers into case insensitive array
        $key_map = array_change_key_case($this->headers, CASE_LOWER);

        // update expires header with provided date
        $key_map['expires'] = $date->toRfc7231String();
        $this->expires_at = strlen($key_map['expires']) > 2 ? $key_map['expires'] : 'now';
        $this->headers = $key_map;
    }
}
