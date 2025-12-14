<?php
// Errors
$lang['Dynadot.!error.user.valid'] = 'Please enter a user.';
$lang['Dynadot.!error.key.valid'] = 'Please enter a key.';
$lang['Dynadot.!error.key.valid_connection'] = ' The user and key combination appear to be invalid, or your Dynadot account may not be configured to allow API access.';
$lang['Dynadot.!error.portfolio.valid_portfolio'] = 'The portfolio entered does not appear to be valid.';
$lang['Dynadot.!error.payment_id.valid_format'] = 'Payment ID must be numeric.';
$lang['Dynadot.!error.domain.valid'] = 'Invalid domain name.';
$lang['Dynadot.!error.domain.unavailable'] = 'Domain is not available.';
$lang['Dynadot.!error.epp.empty'] = 'EPP code is required for transfers.';

$lang['Dynadot.!success.contact_deleted'] = 'The contact has been successfully deleted.';
$lang['Dynadot.!success.epp_code_sent'] = 'The EPP code has been sent to the registrant email.';

// Module Labels
$lang['Dynadot.name'] = 'Dynadot';
$lang['Dynadot.description'] = 'Dynadot is an ICANN accredited domain registrar and web host.';
$lang['Dynadot.module_row'] = 'Account';
$lang['Dynadot.module_row_plural'] = 'Accounts';

// Module Manage
$lang['Dynadot.manage.box_title'] = 'Manage Dynadot Accounts';
$lang['Dynadot.manage.title'] = 'Dynadot Accounts';
$lang['Dynadot.manage.module_row_title'] = 'Account';
$lang['Dynadot.manage.module_groups_title'] = 'Groups';
$lang['Dynadot.manage.module_rows_options'] = 'Options';
$lang['Dynadot.manage.module_rows.edit'] = 'Edit';
$lang['Dynadot.manage.module_rows.delete'] = 'Delete';
$lang['Dynadot.manage.module_rows.confirm_delete'] = 'Are you sure you want to delete this account?';
$lang['Dynadot.manage.module_rows_no_results'] = 'There are no accounts.';

// Module Management
$lang['Dynadot.add_row.box_title'] = 'Add Dynadot Account';
$lang['Dynadot.add_row.basic_title'] = 'Basic Settings';
$lang['Dynadot.add_row.field_user'] = 'User (Not used by API, for internal reference)';
$lang['Dynadot.add_row.field_key'] = 'Key';
$lang['Dynadot.add_row.field_sandbox'] = 'Sandbox';
$lang['Dynadot.add_row.add_btn'] = 'Add Account';

$lang['Dynadot.edit_row.box_title'] = 'Edit Dynadot Account';
$lang['Dynadot.edit_row.basic_title'] = 'Basic Settings';
$lang['Dynadot.edit_row.field_user'] = 'User';
$lang['Dynadot.edit_row.field_key'] = 'Key';
$lang['Dynadot.edit_row.field_sandbox'] = 'Sandbox';
$lang['Dynadot.edit_row.add_btn'] = 'Update Account';

$lang['Dynadot.package_fields.type'] = 'Type';
$lang['Dynadot.package_fields.type_domain'] = 'Domain Registration';
$lang['Dynadot.package_fields.tld_options'] = 'TLDs';
$lang['Dynadot.package_fields.ns1'] = 'Name Server 1';
$lang['Dynadot.package_fields.ns2'] = 'Name Server 2';
$lang['Dynadot.package_fields.ns3'] = 'Name Server 3';
$lang['Dynadot.package_fields.ns4'] = 'Name Server 4';
$lang['Dynadot.package_fields.ns5'] = 'Name Server 5';
$lang['Dynadot.package_fields.epp_code'] = 'EPP Code';
$lang['Dynadot.package_fields.enable_epp_code'] = 'Allow User to Fetch';

// Service Management
$lang['Dynadot.tab_whois.title'] = 'Whois';
$lang['Dynadot.tab_whois.section_registrant'] = 'Registrant';
$lang['Dynadot.tab_whois.section_admin'] = 'Administrative';
$lang['Dynadot.tab_whois.section_technical'] = 'Technical';
$lang['Dynadot.tab_whois.section_billing'] = 'Billing';
$lang['Dynadot.tab_whois.field_submit'] = 'Update Whois';

$lang['Dynadot.tab_nameservers.title'] = 'Name Servers';
$lang['Dynadot.tab_nameservers.field_ns'] = 'Name Server %1$s';
$lang['Dynadot.tab_nameservers.field_submit'] = 'Update Name Servers';

$lang['Dynadot.tab_settings.title'] = 'Settings';
$lang['Dynadot.tab_settings.field_registrar_lock'] = 'Registrar Lock';
$lang['Dynadot.tab_settings.field_registrar_lock_yes'] = 'Set Registrar Lock. Recommended to prevent unauthorized transfer.';
$lang['Dynadot.tab_settings.field_registrar_lock_no'] = 'Release Registrar Lock so the domain can be transferred.';
$lang['Dynadot.tab_settings.field_request_epp'] = 'Request EPP Code/Auth Key';
$lang['Dynadot.tab_settings.field_submit'] = 'Update Settings';

$lang['Dynadot.tab_hosts.title'] = 'Host Names';
$lang['Dynadot.tab_hosts.field_hostname'] = 'Host Name';
$lang['Dynadot.tab_hosts.field_ip'] = 'IP Address';
$lang['Dynadot.tab_hosts.field_submit'] = 'Update Host Names';
$lang['Dynadot.tab_hosts.field_add'] = 'Add Host';
$lang['Dynadot.tab_hosts.field_delete'] = 'Delete';

$lang['Dynadot.tab_dnssec.title'] = 'DNSSEC';
$lang['Dynadot.tab_dnssec.title_add'] = 'Add DNSSEC Record';
$lang['Dynadot.tab_dnssec.field_delete'] = 'Delete';
$lang['Dynadot.tab_dnssec.field_add'] = 'Add Record';
$lang['Dynadot.dnssec.key_tag'] = 'Key Tag';
$lang['Dynadot.dnssec.algorithm'] = 'Algorithm';
$lang['Dynadot.dnssec.digest_type'] = 'Digest Type';
$lang['Dynadot.dnssec.digest'] = 'Digest';

$lang['Dynadot.tab_dnsrecords.title'] = 'DNS Records';
$lang['Dynadot.tab_dnsrecords.warning_overwrite'] = 'Warning: Saving this form will overwrite all existing DNS records for this domain.';
$lang['Dynadot.tab_dnsrecords.field_submit'] = 'Update DNS Records';
$lang['Dynadot.dnsrecord.record_type'] = 'Record Type';
$lang['Dynadot.dnsrecord.host'] = 'Host';
$lang['Dynadot.dnsrecord.value'] = 'Value';

$lang['Dynadot.tab_email_forwarding.title'] = 'Email Forwarding';
$lang['Dynadot.tab_email_forwarding.warning_overwrite'] = 'Warning: Saving this form will overwrite all existing email forwarding rules for this domain.';
$lang['Dynadot.tab_email_forwarding.field_submit'] = 'Update Email Forwarding';
$lang['Dynadot.email_forwarding.username'] = 'User (e.g. "admin" for admin@domain.com)';
$lang['Dynadot.email_forwarding.destination'] = 'Destination Email';

// Domain Transfer
$lang['Dynadot.transfer.DomainName'] = 'Domain Name';
$lang['Dynadot.transfer.EPPCode'] = 'EPP Code';

// Domain Fields
$lang['Dynadot.domain.RegistrantNexus'] = 'Registrant Type';
$lang['Dynadot.domain.RegistrantNexus.c11'] = 'US citizen';
$lang['Dynadot.domain.RegistrantNexus.c12'] = 'Permanent resident of the US';
$lang['Dynadot.domain.RegistrantNexus.c21'] = 'US entity or organization';
$lang['Dynadot.domain.RegistrantNexus.c31'] = 'Foreign organization';
$lang['Dynadot.domain.RegistrantNexus.c32'] = 'Foreign organization with an office in the US';

$lang['Dynadot.domain.RegistrantPurpose'] = 'Purpose';
$lang['Dynadot.domain.RegistrantPurpose.p1'] = 'Business use for profit';
$lang['Dynadot.domain.RegistrantPurpose.p2'] = 'Non-profit business';
$lang['Dynadot.domain.RegistrantPurpose.p3'] = 'Personal use';
$lang['Dynadot.domain.RegistrantPurpose.p4'] = 'Education';
$lang['Dynadot.domain.RegistrantPurpose.p5'] = 'Government';

// Nameservers
$lang['Dynadot.nameserver.ns1'] = 'Name Server 1';
$lang['Dynadot.nameserver.ns2'] = 'Name Server 2';
$lang['Dynadot.nameserver.ns3'] = 'Name Server 3';
$lang['Dynadot.nameserver.ns4'] = 'Name Server 4';
$lang['Dynadot.nameserver.ns5'] = 'Name Server 5';

// Whois Fields
$lang['Dynadot.whois.RegistrantFirstName'] = 'First Name';
$lang['Dynadot.whois.RegistrantLastName'] = 'Last Name';
$lang['Dynadot.whois.RegistrantOrganization'] = 'Organization';
$lang['Dynadot.whois.RegistrantAddress1'] = 'Address 1';
$lang['Dynadot.whois.RegistrantAddress2'] = 'Address 2';
$lang['Dynadot.whois.RegistrantCity'] = 'City';
$lang['Dynadot.whois.RegistrantStateProvince'] = 'State/Province';
$lang['Dynadot.whois.RegistrantPostalCode'] = 'Postal Code';
$lang['Dynadot.whois.RegistrantCountry'] = 'Country';
$lang['Dynadot.whois.RegistrantPhone'] = 'Phone';
$lang['Dynadot.whois.RegistrantEmailAddress'] = 'Email';

$lang['Dynadot.notice.default_nameservers'] = 'You are currently using the default name servers.';
