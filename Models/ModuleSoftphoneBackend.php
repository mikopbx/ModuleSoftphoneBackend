<?php
/**
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by MikoPBX Team
 *
 */

namespace Modules\ModuleSoftphoneBackend\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * ModuleSoftphoneBackend Model
 */
class ModuleSoftphoneBackend extends ModulesModelsBase
{
    /**
     * @Primary
     * * @Identity
     * * @Column(type="integer", nullable=false)
     */
    public string $id;

    /**
     * Toggle
     *
     * @Column(type="integer", default="0", nullable=true)
     */
    public string $disabled;

    /**
     * @Column(type="string", nullable=true, default="")
     */
    public string $settings;

    public function initialize(): void
    {
        $this->setSource('m_ModuleSoftphoneBackend');
        parent::initialize();
    }
}

