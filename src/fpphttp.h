#pragma once
/*
 * This file is part of the Falcon Player (FPP) and is Copyright (C)
 * 2013-2022 by the Falcon Player Developers.
 *
 * The Falcon Player (FPP) is free software, and is covered under
 * multiple Open Source licenses.  Please see the included 'LICENSES'
 * file for descriptions of what files are covered by each license.
 *
 * This source file is covered under the LGPL v2.1 as described in the
 * included LICENSE.LGPL file.
 */

// Compatibility layer for HTTP handling using Drogon framework.
// This replaces the previous libhttpserver-based implementation.
// Only include the minimal drogon headers to avoid DrObject auto-registration
// in translation units that don't need the full framework.

#include <drogon/HttpRequest.h>
#include <drogon/HttpResponse.h>

// Trantor (drogon dependency) defines these as macros which conflict
// with FPP's LogLevel enum values in log.h. Undefine them here.
#ifdef LOG_WARN
#undef LOG_WARN
#endif
#ifdef LOG_INFO
#undef LOG_INFO
#endif
#ifdef LOG_DEBUG
#undef LOG_DEBUG
#endif

#include <functional>
#include <memory>
#include <string>
#include <vector>

// Type aliases to simplify handler signatures
using HttpRequestPtr = drogon::HttpRequestPtr;
using HttpResponsePtr = drogon::HttpResponsePtr;
using HttpCallback = std::function<void(const HttpResponsePtr&)>;

// Helper to split a URL path into pieces (equivalent to libhttpserver's get_path_pieces())
inline std::vector<std::string> getPathPieces(const std::string& path) {
    std::vector<std::string> pieces;
    std::string piece;
    for (size_t i = 0; i < path.size(); ++i) {
        if (path[i] == '/') {
            if (!piece.empty()) {
                pieces.push_back(piece);
                piece.clear();
            }
        } else {
            piece += path[i];
        }
    }
    if (!piece.empty()) {
        pieces.push_back(piece);
    }
    return pieces;
}

// Helper to create a string response (equivalent to httpserver::string_response)
inline HttpResponsePtr makeStringResponse(const std::string& body, int statusCode = 200,
                                           const std::string& contentType = "text/plain") {
    auto resp = drogon::HttpResponse::newHttpResponse();
    resp->setStatusCode(static_cast<drogon::HttpStatusCode>(statusCode));
    resp->setBody(body);
    if (contentType == "application/json") {
        resp->setContentTypeCode(drogon::CT_APPLICATION_JSON);
    } else {
        resp->setContentTypeCodeAndCustomString(drogon::CT_CUSTOM, contentType);
    }
    return resp;
}

// Helper to get a request parameter (query string or form parameter)
// Equivalent to libhttpserver's req.get_arg("key")
inline std::string getRequestArg(const HttpRequestPtr& req, const std::string& key) {
    return req->getParameter(key);
}

// Helper to get request body as string
// Equivalent to libhttpserver's req.get_content()
inline std::string getRequestContent(const HttpRequestPtr& req) {
    return std::string(req->body());
}

// Helper to get query string
// Equivalent to libhttpserver's req.get_querystring()
inline std::string getQueryString(const HttpRequestPtr& req) {
    return req->query();
}
