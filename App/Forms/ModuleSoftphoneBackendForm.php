<?php
/**
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by MikoPBX Team
 *
 */
namespace Modules\ModuleSoftphoneBackend\App\Forms;

use Phalcon\Forms\Form;
use Phalcon\Forms\Element\Text;
use Phalcon\Forms\Element\Numeric;
use Phalcon\Forms\Element\Check;
use Phalcon\Forms\Element\Hidden;


class ModuleSoftphoneBackendForm extends Form
{

    public function initialize($entity = null, $options = null): void
    {
        $this->add(new Hidden('id', ['value' => $entity->id]));

        $this->add(new Numeric('externalPort', [
            'maxlength' => 5,
            'style' => 'width: 150px;',
            'value' => $entity->externalPort ?: '8988',
        ]));

        $this->addCheckBox('useHttps', intval($entity->useHttps) === 1);

        $this->add(new Text('urlPrefix', [
            'readonly' => 'readonly',
            'value' => $entity->urlPrefix ?: '',
        ]));
    }

    /**
     * Adds a checkbox to the form field with the given name.
     *
     * @param string $fieldName The name of the form field.
     * @param bool $checked Indicates whether the checkbox is checked by default.
     * @param string $checkedValue The value assigned to the checkbox when it is checked.
     * @return void
     */
    public function addCheckBox(string $fieldName, bool $checked, string $checkedValue = 'on'): void
    {
        $checkAr = ['value' => null];
        if ($checked) {
            $checkAr = ['checked' => $checkedValue, 'value' => $checkedValue];
        }
        $this->add(new Check($fieldName, $checkAr));
    }
}
