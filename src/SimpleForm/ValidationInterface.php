<?php

namespace SimpleForm;

interface ValidationInterface
{

    /**
     * @return array
     */
    function getValidations();

    /**
     * @return array
     */
    function getMessages();

    /**
     * @return array
     */
    function getAlias();
}
