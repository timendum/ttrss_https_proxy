Tiny Tiny RSS plugin: HTTPS Proxy
===========

This plugin proxies all images via built-in secure proxy.

It's incompatible with `af_proxy_http`.

## Installation

Git clone to ``plugins.local/https_proxy``.

Example:

	cd ttrss folder
	cd plugins.local
	git clone https://github.com/timendum/ttrss_https_proxy.git https_proxy

The folder **must** be named "https_proxy", otherwise the plugin will not appear.

Then enable the plugin in the preferences.


## History

Based on `af_proxy_http` standard TTRSS plugin, by fox.

I added a customizable whitelist of sites that will not need the proxy
and removed "Enable proxy for all" preference.