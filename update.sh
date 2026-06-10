#!/bin/bash
# Compatibility wrapper for V2Ray Store unified installer/updater.
bash <(curl -s https://raw.githubusercontent.com/0fariid0/v2ray-store/main/v2raystore.sh) "${@:-update}"
