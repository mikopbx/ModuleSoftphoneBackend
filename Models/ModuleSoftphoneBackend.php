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
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * Toggle
     *
     * @Column(type="integer", default="0", nullable=true)
     */
    public $disabled;

    /**
     * @Column(type="string", nullable=true, default="")
     */
    public $settings;

    /**
     * External server port
     * @Column(type="integer", default="8988", nullable=true)
     */
    public $externalPort;

    /**
     * Use HTTPS for external server (0=HTTP, 1=HTTPS)
     * @Column(type="integer", default="0", nullable=true)
     */
    public $useHttps;

    /**
     * URL prefix for external server access (32+ chars, URL-safe)
     * @Column(type="string", nullable=true, default="")
     */
    public $urlPrefix;

    public function initialize(): void
    {
        $this->setSource('m_ModuleSoftphoneBackend');
        parent::initialize();
    }
}

