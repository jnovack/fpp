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
    
    # Remove real libhttpserver shared libraries to prevent symbol conflicts
    # with FPP's compatibility shim in libfpp.so. Plugins that still link
    # -lhttpserver would load the real library, causing a SEGV when the
    # shim's webserver object is passed to the real register_resource().
    rm -f /lib/libhttpserver.so* /usr/lib/libhttpserver.so* /usr/local/lib/libhttpserver.so*
fi
