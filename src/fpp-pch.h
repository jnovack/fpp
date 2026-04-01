#ifndef __FPP_PCH__
#define __FPP_PCH__

// This #define must be before any #include's
#define _FILE_OFFSET_BITS 64

// Block libhttpserver from loading: FPP now uses drogon, and fpphttp.h provides
// source-level shims in the httpserver:: namespace for plugin compatibility.
// Defining the guard here causes any #include <httpserver.hpp> in plugin code
// to be silently skipped, preventing redefinition conflicts with our shims.
#define SRC_HTTPSERVER_HPP_

// Kept for backward compatibility with external plugins compiled against the
// old libhttpserver-based API. See CLAUDE.md.
#define HTTP_RESPONSE_CONST

#if __has_include(<jsoncpp/json/json.h>)
#include <jsoncpp/json/json.h>
#elif __has_include(<json/json.h>)
#include <json/json.h>
#endif

#ifndef NOPCH
#include <algorithm>
#include <array>
#include <atomic>
#include <chrono>
#include <cmath>
#include <cstring>
#include <ctime>
#include <fstream>
#include <iomanip>
#include <iostream>
#include <list>
#include <map>
#include <mutex>
#include <set>
#include <sstream>
#include <string>
#include <thread>
#include <vector>

#include <sys/types.h>
#include <errno.h>
#include <fcntl.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <strings.h>
#include <unistd.h>

#include "Events.h"
#include "Sequence.h"
#include "Warnings.h"
#include "common.h"
#include "fppversion.h"
#include "log.h"
#include "settings.h"

#endif

#endif
