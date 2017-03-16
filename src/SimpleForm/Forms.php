<?php

namespace SimpleForm;

use Buuum\Filter;
use Buuum\Validation;

class Forms
{

    /**
     * @var string
     */
    protected $type_variant = 'default';

    /**
     * @var
     */
    protected $type;

    /**
     * @var FilterInterface
     */
    protected $filter;

    /**
     * @var ValidationInterface
     */
    protected $validation;

    /**
     * @var array
     */
    protected $relatedForms = [];

    /**
     * @var null|callable
     */
    protected $onStartValidation = null;

    /**
     * @var null|callable
     */
    protected $onSuccess = null;

    /**
     * @var null|callable
     */
    protected $onError = null;

    /**
     * @param $data
     * @return array|mixed
     */
    public function filter($data)
    {

        $types = $this->{$this->type}();

        $filter = new Filter($this->getFilters($types));
        $data = $filter->filter($data);

        if (!empty($types['relations'])) {

            $relations = $this->relatedForms;
            foreach ($types['relations'] as $relation_name) {
                /** @var Forms $newr */
                $newr = new $relations[$relation_name]['form_class']($relations[$relation_name]['validation_type'][$this->type]);
                if ($relations[$relation_name]['relation_type'] == 'one') {
                    $filt = $newr->filter([
                        $relation_name => $data[$relation_name]
                    ]);
                    $data[$relation_name] = $filt[$relation_name];
                } else {
                    if (!empty($data[$relation_name])) {
                        foreach ($data[$relation_name] as $key => $relationdata) {
                            $data[$relation_name][$key] = $newr->filter($relationdata);
                        }
                    } else {
                        $data[$relation_name] = [];
                    }
                }
            }
        }

        if (!empty($types['extra_filters'])) {
            foreach ($types['extra_filters'] as $function) {
                $data = call_user_func([$this->filter, $function], $data);
            }
        }

        return $data;
    }

    /**
     * @param $data
     * @param bool $name
     * @return array|bool
     */
    public function validate($data, $name = false)
    {
        $error = [];
        $errors = [];

        $types = $this->{$this->type}();
        $alias = $this->validation->getAlias();

        $validation = new Validation($this->getValidations($types), $this->validation->getMessages(), $alias);

        if (!empty($types['relations'])) {
            $relations = $this->relatedForms;
            foreach ($types['relations'] as $relation_name) {
                /** @var Forms $newr */
                $newr = new $relations[$relation_name]['form_class']($relations[$relation_name]['validation_type'][$this->type]);
                $alias_ = (!empty($alias[$relation_name])) ? $alias[$relation_name] : $relation_name;
                if ($relations[$relation_name]['relation_type'] == 'one') {
                    $value = $data[$relation_name];
                    if ($error_ = $newr->validate($value, $relation_name)) {
                        if (!isset($error_[$relation_name])) {
                            $errors[$alias_][0] = $error_;
                        } else {
                            $errors[$alias_] = $error_[$relation_name];
                        }
                    }
                } else {
                    $value = $data[$relation_name];
                    foreach ($value as $k => $v) {
                        if ($error_ = $newr->validate($v, $relation_name)) {
                            $errors[$alias_ . ' ' . ($k + 1)][0] = $error_;
                        }
                    }
                }
            }
        }

        if (!$validation_data = $validation->validate($data)) {
            $error = $validation->getErrors();
        }

        if (!empty($errors) || !empty($error)) {
            return array_merge($error, $errors);
        }

        if (!empty($types['extra_validations'])) {
            $errors = [];
            foreach ($types['extra_validations'] as $function) {
                if ($error = call_user_func([$this->validation, $function], $data)) {
                    if ($name) {
                        $errors[$name][] = $error;
                    } else {
                        $errors[] = $error;
                    }
                }
            }
            if (!empty($errors)) {
                return $errors;
            }
        }

        return false;
    }

    /**
     * @param $types
     * @return array
     */
    protected function getFilters($types)
    {
        $fields = $types['fields'];

        return array_filter($this->filter->getFilters(), function ($k) use ($fields) {
            return in_array($k, $fields);
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param $types
     * @return array
     */
    protected function getValidations($types)
    {
        $fields = $types['fields'];

        return array_filter($this->validation->getValidations(), function ($k) use ($fields) {
            return in_array($k, $fields);
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param callable $success
     * @param callable $render
     * @param array $data
     * @return mixed
     */
    public function submit(callable $success, callable $render, array $data)
    {
        $data = $this->filter($data);

        if (!$errors = $this->validate($data)) {
            $this->startValidation();
            try {
                $response = call_user_func($success, $data);
                $this->success();
                return $response;
            } catch (\Exception $e) {
                $errors = [$e->getMessage()];
                $this->error();
            }
        }

        return call_user_func($render, $data, $errors);

    }

    protected function startValidation()
    {
        if ($this->onStartValidation) {
            call_user_func($this->onStartValidation);
        }
    }

    protected function success()
    {
        if ($this->onSuccess) {
            call_user_func($this->onSuccess);
        }
    }

    protected function error()
    {
        if ($this->onError) {
            call_user_func($this->onError);
        }
    }


}
