# Changelog

## 0.1.1 (2026-11-07)
- Fixed: every signed-in REST request (`/loft/v1/me`, bookings, checkout, billing portal, attendees, waivers and the staff `admin/*` routes) ended in a fatal error. Its permission callbacks `Router::member()` and `Router::staff()` were private, so WordPress couldn't call them. They're public now.

## 0.1.0
- First version: programs, sessions, seasons, plans with Stripe checkout, entitlements, bookings with a waitlist, waivers, family attendees, staff check-in, emails, and the `/loft/v1` REST API.
