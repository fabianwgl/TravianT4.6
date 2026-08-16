# Third-party and asset inventory

This is a working provenance inventory, not a legal conclusion. “Unresolved”
means the repository does not currently contain enough evidence to grant new
redistribution rights.

| Material | Location | Evidence/status |
| --- | --- | --- |
| Securimage CAPTCHA | `main_script/include/Plugins/securimage/` | Source headers identify Securimage and a BSD licence; retain upstream notices. |
| jQuery 3.2.1 | `main_script/copyable/public/js/default/jquery-3.2.1.min.js` | Upstream project is MIT licensed; exact vendored notice still needs normalization. |
| jQuery MD5 plugin | `main_script/copyable/public/js/default/jquery.md5.min.js` | Vendored source retained; exact source and licence evidence unresolved. |
| jQuery Scrollbar | `main_script/copyable/public/js/default/jquery.scrollbar.min.js` | Vendored source retained; exact source and licence evidence unresolved. |
| Legacy game artwork and interface sprites | `main_script/copyable/public/` | Provenance unresolved; replacement or qualified review required. |
| Legacy audio and Flash files | game public/plugin directories | Provenance unresolved; obsolete formats should be removed or replaced. |
| Legacy game text and translations | `main_script/include/resources/Translation/` | Provenance unresolved; replacement or qualified review required. |
| Original modernization code and OpenVillage launcher | `docker/`, `scripts/`, `web/`, maintained patches | Covered only to the extent stated by the root licence and contributor rights. |

Removed during modernization:

- unused D3 and d3pie bundles;
- all legacy TweenMax files and every standalone or embedded copy of the separately licensed MorphSVG Club GreenSock plugin, with live calls replaced by native DOM/SVG updates;
- four unreachable duplicate graphic packs, including the broken T4.4 variants;
- phpMyAdmin and legacy hosted-service/vendor trees outside the maintained app.

Contributors must record the upstream source, version, licence, and required
notice for every new dependency or asset.
