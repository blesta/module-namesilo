<?php
// Dynadot configuration
Configure::set('Dynadot.email_templates', [
    'en_us' => [
        'lang' => 'en_us',
        'text' => 'Your domain transfer authorization code is {auth_code}.',
        'html' => 'Your domain transfer authorization code is {auth_code}.'
    ]
]);

// TLDs and their supported fields
// This is a placeholder. Dynadot supports many TLDs and fields.
// You might need to expand this based on specific TLD requirements.
Configure::set('Dynadot.domain_fields', [
    // ... specific TLD fields if any
]);

Configure::set('Dynadot.domain_fields.us', [
    'usnc' => [
        'label' => Language::_('Dynadot.domain.RegistrantNexus', true),
        'type' => 'select',
        'options' => [
            'C11' => Language::_('Dynadot.domain.RegistrantNexus.c11', true),
            'C12' => Language::_('Dynadot.domain.RegistrantNexus.c12', true),
            'C21' => Language::_('Dynadot.domain.RegistrantNexus.c21', true),
            'C31' => Language::_('Dynadot.domain.RegistrantNexus.c31', true),
            'C32' => Language::_('Dynadot.domain.RegistrantNexus.c32', true)
        ]
    ],
    'usap' => [
        'label' => Language::_('Dynadot.domain.RegistrantPurpose', true),
        'type' => 'select',
        'options' => [
            'P1' => Language::_('Dynadot.domain.RegistrantPurpose.p1', true),
            'P2' => Language::_('Dynadot.domain.RegistrantPurpose.p2', true),
            'P3' => Language::_('Dynadot.domain.RegistrantPurpose.p3', true),
            'P4' => Language::_('Dynadot.domain.RegistrantPurpose.p4', true),
            'P5' => Language::_('Dynadot.domain.RegistrantPurpose.p5', true)
        ]
    ]
]);

Configure::set('Dynadot.nameserver_fields', [
    'ns1' => [
        'label' => Language::_('Dynadot.nameserver.ns1', true),
        'type' => 'text'
    ],
    'ns2' => [
        'label' => Language::_('Dynadot.nameserver.ns2', true),
        'type' => 'text'
    ],
    'ns3' => [
        'label' => Language::_('Dynadot.nameserver.ns3', true),
        'type' => 'text'
    ],
    'ns4' => [
        'label' => Language::_('Dynadot.nameserver.ns4', true),
        'type' => 'text'
    ],
    'ns5' => [
        'label' => Language::_('Dynadot.nameserver.ns5', true),
        'type' => 'text'
    ]
]);

Configure::set('Dynadot.transfer_fields', [
    'domain' => [
        'label' => Language::_('Dynadot.transfer.DomainName', true),
        'type' => 'text'
    ],
    'auth' => [
        'label' => Language::_('Dynadot.transfer.EPPCode', true),
        'type' => 'text'
    ]
]);

$whois_sections = ['Registrant', 'Admin', 'Technical', 'Billing'];
$whois_fields = [];

foreach ($whois_sections as $section) {
    $whois_fields[$section . 'FirstName'] = [
        'label' => Language::_('Dynadot.whois.' . $section . 'FirstName', true),
        'type' => 'text',
        'rp' => 'fn',
        'lp' => 'first_name'
    ];
    $whois_fields[$section . 'LastName'] = [
        'label' => Language::_('Dynadot.whois.' . $section . 'LastName', true),
        'type' => 'text',
        'rp' => 'ln',
        'lp' => 'last_name'
    ];
    $whois_fields[$section . 'Organization'] = [
        'label' => Language::_('Dynadot.whois.' . $section . 'Organization', true),
        'type' => 'text',
        'rp' => 'cp',
        'lp' => 'company'
    ];
    $whois_fields[$section . 'Address1'] = [
        'label' => Language::_('Dynadot.whois.' . $section . 'Address1', true),
        'type' => 'text',
        'rp' => 'ad',
        'lp' => 'address1'
    ];
    $whois_fields[$section . 'Address2'] = [
        'label' => Language::_('Dynadot.whois.' . $section . 'Address2', true),
        'type' => 'text',
        'rp' => 'ad2',
        'lp' => 'address2'
    ];
    $whois_fields[$section . 'City'] = [
        'label' => Language::_('Dynadot.whois.' . $section . 'City', true),
        'type' => 'text',
        'rp' => 'cy',
        'lp' => 'city'
    ];
    $whois_fields[$section . 'StateProvince'] = [
        'label' => Language::_('Dynadot.whois.' . $section . 'StateProvince', true),
        'type' => 'text',
        'rp' => 'st',
        'lp' => 'state'
    ];
    $whois_fields[$section . 'PostalCode'] = [
        'label' => Language::_('Dynadot.whois.' . $section . 'PostalCode', true),
        'type' => 'text',
        'rp' => 'zp',
        'lp' => 'zip'
    ];
    $whois_fields[$section . 'Country'] = [
        'label' => Language::_('Dynadot.whois.' . $section . 'Country', true),
        'type' => 'text',
        'rp' => 'ct',
        'lp' => 'country'
    ];
    $whois_fields[$section . 'Phone'] = [
        'label' => Language::_('Dynadot.whois.' . $section . 'Phone', true),
        'type' => 'text',
        'rp' => 'ph',
        'lp' => 'phone'
    ];
    $whois_fields[$section . 'EmailAddress'] = [
        'label' => Language::_('Dynadot.whois.' . $section . 'EmailAddress', true),
        'type' => 'text',
        'rp' => 'em',
        'lp' => 'email'
    ];
}

Configure::set('Dynadot.whois_fields', $whois_fields);

Configure::set('Dynadot.dns_records', [
    'A' => 'A',
    'AAAA' => 'AAAA',
    'CNAME' => 'CNAME',
    'MX' => 'MX',
    'TXT' => 'TXT',
    'SRV' => 'SRV',
    'FORWARD' => 'FORWARD',
    'STEALTH' => 'STEALTH',
    'EMAIL' => 'EMAIL'
]);
