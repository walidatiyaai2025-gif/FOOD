# FOODEX 1.0.40 Distribution Notes

This release synchronizes Dashboard, Customer and Driver artifacts on `1.0.40` / mobile build `+40`.

The deployable Dashboard delta since 1.0.39 contains the production hotfixes from #585/#586: nullable coupon start/end timestamps no longer crash the Coupons page, and malformed Customer 360 placeholder references are redirected safely instead of producing route 404 warnings.

Publishing 1.0.40 does not modify production AppVersion rows, does not change the production minimum-supported Driver version, and does not activate Driver location enforcement.
