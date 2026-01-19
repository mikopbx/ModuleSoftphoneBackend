<?php

/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2024 Alexey Portnov and Nikolay Beketov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <https://www.gnu.org/licenses/>.
 */

namespace Modules\ModuleSoftphoneBackend\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * Class PhoneBook
 *
 * @package Modules\ModulePhoneBook\Models
 *
 * @Indexes(
 *     [name='number', columns=['number'], type=''],
 *     [name='Created', columns=['created'], type='']
 * )
 */
class PhoneBook extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * Number for search i.e. 9065554343
     *
     * @Primary
     * @Column(type="integer", nullable=true)
     */
    public $number;

    /**
     * Number in visual format +7(926)991-12-12
     *
     * @Column(type="string", nullable=true)
     */
    public $number_rep;

    /**
     * CallerID - MIKO - Nikolay Beketov
     *
     * @Column(type="string", nullable=true)
     */
    public $call_id;

    /**
     * @Column(type="string", nullable=true)
     */
    public $client;

    /**
     * @Column(type="string", nullable=true)
     */
    public $contact;

    /**
     * @Column(type="string", nullable=true, default="")
     */
    public ?string $ref = "";

    /**
     * Created - Created timestamp or 0
     *
     * @Column(type="integer", nullable=false, default=0)
     */
    public int $created = 0;

    /**
     * Created - Created timestamp or 0
     *
     * @Column(type="integer", nullable=false, default=0)
     */
    public int $changed = 0;

    /**
     * @Column(type="integer", nullable=false, default=0)
     */
    public int $is_employee = 0;

    /**
     * Initializes the model by setting the source table,
     * calling the parent initializer, and enabling dynamic updates.
     *
     * @return void
     */
    public function initialize(): void
    {
        $this->setSource('m_PhoneBook');
        parent::initialize();
        $this->useDynamicUpdate(true);
    }
}
