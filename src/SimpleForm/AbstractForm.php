<?php

namespace SimpleForm;

use Buuum\Filter;
use Buuum\Validation;

abstract class AbstractForm
{
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
    protected $fields = [];

    /**
     * @var array
     */
    protected $relations = [];

    /**
     * @var array
     */
    protected $data = [];

    /**
     * @var array
     */
    protected $errors = [];

    /**
     * @return array
     */
    abstract public function getRelatedForms();

    /**
     * AbstractForm constructor.
     * @param $fields
     * @param FilterInterface $filter
     * @param ValidationInterface $validation
     */
    public function __construct($fields, FilterInterface $filter, ValidationInterface $validation)
    {
        $this->filter = $filter;
        $this->validation = $validation;

        $this->fields = $fields;
        $this->relations = $this->getRelations($fields['relations']);
    }

    /**
     * @param $data
     * @return array|mixed
     */
    protected function filter($data)
    {

        $filters = $this->getFilters($this->fields['fields']);
        $extrafilters = $this->fields['extra_filters'];

        $filter = new Filter($filters);
        $data = $filter->filter($data);

        if (!empty($this->relations)) {
            foreach ($this->relations as $relation_name => $relation) {
                /** @var AbstractForm $relation_class */
                $relation_class = $relation['class'];
                if ($relation['type'] == 'one') {
                    if (!empty($data[$relation_name])) {
                        $data[$relation_name] = $relation_class->filter($data[$relation_name]);
                    }
                } else {
                    if (!empty($data[$relation_name])) {
                        foreach ($data[$relation_name] as $key => $relationdata) {
                            $data[$relation_name][$key] = $relation_class->filter($relationdata);
                        }
                    } else {
                        $data[$relation_name] = [];
                    }
                }
            }
        }

        if (!empty($extrafilters)) {
            foreach ($extrafilters as $function) {
                $data = call_user_func([$this->filter, $function], $data);
            }
        }

        return $data;

    }

    /**
     * @param $data
     * @return array|bool
     */
    protected function checkValidate($data)
    {
        $error = $errors = [];

        $validations = $this->getValidations($this->fields['fields']);
        $extravalidations = $this->fields['extra_validations'];

        $alias = $this->validation->getAlias();
        $validation = new Validation($validations, $this->validation->getMessages(), $alias);

        if (!empty($this->relations)) {
            foreach ($this->relations as $relation_name => $relation) {
                /** @var AbstractForm $relation_class */
                $relation_class = $relation['class'];
                if ($relation['type'] == 'one') {
                    $data_relation = !empty($data[$relation_name]) ? $data[$relation_name] : '';
                    if ($errors_ = $relation_class->checkvalidate($data_relation)) {
                        $errors['relations'][$relation_name] = [
                            'alias'            => !empty($alias[$relation_name]) ? $alias[$relation_name] : $relation_name,
                            'input_name'       => $relation_name,
                            'fields'           => !empty($errors_['fields']) ? $errors_['fields'] : [],
                            'extravalidations' => !empty($errors_['extravalidations']) ? $errors_['extravalidations'] : [],
                            'relations'        => (!empty($errors_['relations'])) ? $errors_['relations'] : []
                        ];
                    }
                } else {
                    $value = $data[$relation_name];
                    if (empty($value)) {
                        if ($errors_ = $relation_class->checkvalidate([])) {
                            $errors['relations'][$relation_name]['many'][] = [
                                'alias'            => !empty($alias[$relation_name]) ? $alias[$relation_name] : $relation_name,
                                'input_name'       => $relation_name,
                                'position'         => 0,
                                'fields'           => !empty($errors_['fields']) ? $errors_['fields'] : [],
                                'extravalidations' => !empty($errors_['extravalidations']) ? $errors_['extravalidations'] : [],
                                'relations'        => (!empty($errors_['relations'])) ? $errors_['relations'] : []
                            ];
                        }
                    } else {
                        foreach ($value as $k => $v) {
                            if ($errors_ = $relation_class->checkvalidate($v)) {
                                $errors['relations'][$relation_name]['many'][] = [
                                    'alias'            => !empty($alias[$relation_name]) ? $alias[$relation_name] : $relation_name,
                                    'input_name'       => $relation_name,
                                    'position'         => $k,
                                    'fields'           => !empty($errors_['fields']) ? $errors_['fields'] : [],
                                    'extravalidations' => !empty($errors_['extravalidations']) ? $errors_['extravalidations'] : [],
                                    'relations'        => (!empty($errors_['relations'])) ? $errors_['relations'] : []
                                ];
                            }
                        }
                    }
                }
            }
        }

        if (!$validation_data = $validation->validate($data)) {
            $error = ['fields' => $validation->getErrors()];
        }

        if (empty($error) && !empty($extravalidations)) {
            $extra_errors = [];
            foreach ($extravalidations as $function) {
                if ($extra_error = call_user_func([$this->validation, $function], $data)) {
                    $extra_errors[] = $extra_error;
                }
            }
            if (!empty($extra_errors)) {
                $error['extravalidations'] = $extra_errors;
            }
        }

        if (!empty($errors) || !empty($error)) {
            return array_merge($error, $errors);
        }

        return false;

    }

    /**
     * @param $fields
     * @return array
     */
    protected function getFilters($fields)
    {
        return array_filter($this->filter->getFilters(), function ($k) use ($fields) {
            return in_array($k, $fields);
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param $fields
     * @return array
     */
    protected function getValidations($fields)
    {
        return array_filter($this->validation->getValidations(), function ($k) use ($fields) {
            return in_array($k, $fields);
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param array $data
     */
    public function validate(array $data)
    {
        $this->data = $this->filter($data);
        $this->errors = $this->checkValidate($this->data);
    }

    /**
     * @return array
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * @param array $relations
     * @return array
     */
    protected function getRelations(array $relations)
    {
        $form_relations = [];
        $relations_class = $this->getRelatedForms();
        foreach ($relations as $relation) {
            list($relation_name, $relation_type, $relation_function, $relation_variant) = explode('.', $relation);
            $form_relations[$relation_name] = [
                'class' => new $relations_class[$relation_name]($relation_function, $relation_variant),
                'type'  => $relation_type
            ];
        }

        return $form_relations;
    }

}
