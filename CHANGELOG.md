# Changelog

## [Unreleased]
### Added
- Edit existing DNS records from the client and admin DNS tabs (dnsUpdateRecord), with a confirmation listing each change
- Export Zone File on the client and admin DNS tabs (standard BIND/RFC 1035 zone file)
- Success messages after adding, updating or deleting a DNS record
- Delete confirmations for DNS records using Blesta's confirmation modals
- DNS input checks before calling NameSilo: record type required, empty or non-ASCII values, and a second SPF/DMARC/DKIM record or a DMARC/DKIM record on the wrong host
- Module row setting "Clients can manage WHOIS contacts" (on unless turned off) controlling the client Whois and Manage Contacts tabs
- Module row setting to turn on auto-renew for new registrations and transfers (off by default)
- Module row setting to register new domains with the NameSilo account's default contact (off by default; not used for .ca, or for clients who have a default contact set)
- Ed25519 (15) and Ed448 (16) DNSSEC algorithms
- The client Whois tab shows the domain's contact details as editable fields; changes update the client's own contact or create a new contact for the domain, never a contact shared with other domains
### Fixed
- DNS records, DNSSEC records, email forwarding addresses, host names and transfer status are now HTML-escaped
- TXT values entered with zone-file quoting ("v=spf1 ...") are unquoted before being sent, since NameSilo stores quotes literally
- DNS hosts entered as "@", the bare domain or "host.example.com" are normalized to the relative form the API expects
- The client DNS tab priority (MX) toggle and help text typos
- MX priority 0 was replaced by NameSilo's default of 10 because rrdistance was only sent when non-empty
- Domain transfers are checked with NameSilo's checkTransferAvailability; a domain that can't be transferred no longer activates a service with nothing ordered
- EPP codes with characters such as + or & were mangled on transfer and when changing the EPP code
- Multi-year registrations during a NameSilo promotion created the service without its domain; a failed extension of the remaining years is now logged as a module error
- The .ca contact created for an order was never deleted when the order failed
- Multi-year registration prices used the first-year (promotional) price for every year instead of the renewal price for the additional years
- Domain forwarding dropped the path, query string and fragment of the forwarding URL, and cloaked forwards were not pre-selected
- Email forwarding sent the full address instead of the local part, and more than 5 forwarding addresses were silently dropped
- The Sync date admin action stored midnight expirations as noon
- A failed contact lookup during contact sync was not detected
- Host IPs were numbered by form position (an empty first IP broke the update), and a fully-qualified new host name was not stripped of the domain
- The DNSSEC tabs read key_tag/digest_type instead of NameSilo's keyTag/digestType (client tab)
- Cached TLD prices could not be read back, so pricing came back empty while the cache was warm
- The client Whois tab only accepts the client's own contacts or the domain's current ones
- The Manage Contacts default contact only accepts one of the client's own contacts
- Clients can no longer edit or delete the NameSilo account's default contact profile from Manage Contacts
### Changed
- Formatted all source code to PSR-2 standards for Blesta
- Fixed a couple "undefined variable" errors encountered on initial installation
- Updated the look of the submit buttons
- Fixed a "Undefined property" error, when there are no domains available in Audit Domains

## [1.8.5] = 2018-11-19
### Added
- Automatically remove spaces from beginning/end of domain registrations/transfers
### Changed
- Hide NS fields for admin transfer orders
- Fix multi-year registrations during Namesilo promotions
- Fix exception when editing API info if only one portfolio exists
- Fix disallow domain privacy on .us domains
- Fix require EPP code from client for transfers

## [1.8.4] = 2018-05-22
### Added
- non-200 HTTP return codes parsed as error
### Changed
- Fix admin-side domain registrations
- Open namesilo links in new tab
- Fix undefined property error on DNSSEC page (admin)

## [1.8.3] - 2018-04-13
### Changed
- Fix client DNSSEC format on small screens

## [1.8.2] - 2018-04-11
### Added
- DNSSEC management support

### Changed
- Fix email verification status if NameSilo account has a single email for all domains

## [1.8.1] - 2018-04-10
### Changed
- Fix configuration fields on order form

## [1.8.0] - 2018-04-09
### Added
- Proper support for .us and .ca TLDs
- Input validation for EPP code on transfers
- Support payment_id for registrations & transfers.  See https://www.namesilo.com/api_reference.php#registerDomain
- Set client's info in WHOIS info for domain transfers
- Give client ability to toggle WHOIS privacy
- Set default portfolio for registrations/transfers
- Domain audit (compare namesilo domain list against active/suspended blesta services)
- Tool to sync service's renew date with domain expiration date
- Display domain transfer status on all management pages if its pending transfer
- Client/admin can see email validation status and trigger resending of validation email
- Support for using NameSilo's batch API
- Create/manage registered nameservers

### Changed
- Renamed "Communication" tab to "Admin Actions"
- Fixed dynamically loading TLDs from NameSilo API

### Removed
- Old static TLD list from code

## [Prior Versions]
This changelog starts at version 1.8.0 when @neonardo1 started contributions.
Detailed changelogs before this point may be innacurate.

## [1.5-beta]
### Added
- .club extension
- .online extension
- .pub extension
- .pro extension

## [1.4-beta]
### Added
- .cloud extension

## [1.3-beta]
### Added
- Add Admin Communication tab to send domain-related email notifications.

## [1.2-beta]
### Added
- Disable Settings tab (registrar lock and EPP code) on a per-client basis by adding a custom client field called "Disable Domain Transfers" and checking it on the Client Edit screen.