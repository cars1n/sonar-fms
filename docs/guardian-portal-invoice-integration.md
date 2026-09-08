# Guardian portal invoice integration

## Decision

Sonar FMS remains the system of record for invoices, invoice items, payment schedules, payments, refunds, and generated invoice PDFs. The CRM guardian portal displays that information through a read-only, server-to-server API instead of copying financial records into the CRM database or requiring guardians to sign in to Sonar separately.

PowerSchool identifiers form the integration contract:

- CRM `students.ps_dcid` maps to Sonar `students.sis_id`.
- CRM `entities.ps_dcid` maps to Sonar `schools.sis_id`.

The CRM resolves those identifiers from its authenticated guardian-to-student relationships. Browser requests cannot supply arbitrary student identifiers. Sonar independently scopes every request to the tenant selected by the request host, the requested school, and the complete set of permitted students.

## API boundary

The API lives under `/api/portal/v1` and uses a dedicated bearer token configured with `CRM_PORTAL_API_TOKEN`. It exposes only:

- a paginated invoice list;
- one invoice's guardian-safe details; and
- the generated invoice PDF.

Responses intentionally use `PortalInvoiceResource` rather than the administrative `InvoiceResource`. Internal activity history, staff identities, notes, and payment transaction details are not part of the guardian contract.

Only published invoices whose `available_at` time has arrived are returned. A combined invoice is returned only when every child invoice belongs to the supplied student set, preventing information about another family from being disclosed through a combined total.

## Ownership and operations

- Finance staff continue creating, publishing, assigning, adjusting, and recording payments entirely in Sonar.
- Guardians use the CRM portal for invoice lists, details, and downloads.
- The CRM fetches current data on demand and may cache responses briefly for resilience; it does not persist a second financial ledger.
- Both applications must use matching, rotated secrets and HTTPS.
- Sonar and CRM deployments must be coordinated when the versioned response contract changes.

## Follow-up

The legacy Sonar `/my-invoices` index currently filters on `published_at` but not a future `available_at`. Keep the integration API's stricter visibility rule and bring the legacy page into alignment in a separate change with regression coverage.
