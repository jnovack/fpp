#!/bin/bash
#####################################

BINDIR=$(cd $(dirname $0) && pwd)
. ${BINDIR}/../../scripts/common

# Install drogon framework, replacing libhttpserver as the HTTP server backend.
# libdrogon-dev is apt-installable; libtrantor-dev is pulled in as a dependency.
if [ "${FPPPLATFORM}" != "MacOS" ]; then
    if ! dpkg -l | grep -q libdrogon-dev; then
        echo "FPP - Installing libdrogon-dev (replaces libhttpserver)"
        apt-get update
        apt-get install -y libdrogon-dev
    fi
fi
