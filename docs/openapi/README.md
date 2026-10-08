# OpenAPI Contract

This directory contains the authoritative OpenAPI 3.1
specification for the Wedding Marketplace backend.

## Specification

The contract is [`openapi.yaml`](./openapi.yaml). Its paths are the
Laravel API routes under `/api`; authentication, role requirements,
validation, and lifecycle behavior are documented from the route and
controller implementation.

## Viewing and validating

Open `openapi.yaml` in an OpenAPI 3.1-compatible viewer, or use a local
Swagger UI / Redoc instance. Validate it with an OpenAPI 3.1-aware
validator before relying on generated clients. In particular, check YAML
syntax, component references, unique `operationId` values, and compare
the operations with `php artisan route:list --json`. For example, with
Redocly CLI available, run
`npx @redocly/cli lint docs/openapi/openapi.yaml`.

## Using the contract

Frontend developers can import `openapi.yaml` into an OpenAPI-compatible
client generator or API viewer. The API server base path is `/`; paths in
the contract include `/api`. Protected operations use a Sanctum bearer
token. OAuth redirect routes use the browser session and redirect to the
identity provider rather than returning an API token in the redirect
response.

## Maintenance

Update this contract whenever an API route, validation rule, response,
authorization rule, or state transition changes. Derive changes from
the route list, middleware, controllers, models/resources, and tests;
then validate the specification and compare its method/path pairs with
the runtime route inventory. Do not document configuration secrets,
live credentials, or example OTPs/tokens.

## Thunder Client

The existing [`thunder-tests`](../../thunder-tests) collection is a
manual/request-oriented aid (currently including
[`thunder-collection_reviews-ratings.json`](../../thunder-tests/thunder-collection_reviews-ratings.json)).
It is not a complete API schema and may cover only selected flows. Keep
it consistent with the contract when its requests are maintained; this
OpenAPI document remains the authoritative machine-readable API
contract.