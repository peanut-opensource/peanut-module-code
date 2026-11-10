<?php

declare(strict_types=1);

// Cold module-owned HTTP metadata. No business service, container, credential or DB access.
$metadata = json_decode(<<<'JSON'
{
  "paths": {
    "/adminapi/custom.ai-assistant.textRoutes": {
      "get": {
        "operationId": "aiAssistantTextRoutes",
        "tags": [
          "AiAssistant"
        ],
        "x-peanut-permissions": [
          "custom.ai-assistant.use"
        ],
        "description": "按指定消费模块列出当前成员可使用的文本路由，各级配置均须启用，模型必须在有效期内。",
        "x-peanut-errors": [
          "AI_PERMISSION_DENIED",
          "AI_INPUT_INVALID"
        ],
        "parameters": [
          {
            "in": "query",
            "name": "consumer_module",
            "required": true,
            "schema": {
              "type": "string",
              "maxLength": 96,
              "pattern": "^(general|[a-z][a-z0-9-]*([.][a-z][a-z0-9-]*)+)$"
            }
          },
          {
            "in": "query",
            "name": "page",
            "required": false,
            "schema": {
              "type": "integer",
              "minimum": 1
            }
          }
        ],
        "responses": {
          "200": {
            "description": "可使用的文本路由",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "additionalProperties": false,
                  "properties": {
                    "code": {
                      "type": "integer"
                    },
                    "msg": {
                      "type": "string"
                    },
                    "show": {
                      "type": "integer"
                    },
                    "data": {
                      "type": "object",
                      "additionalProperties": false,
                      "properties": {
                        "list": {
                          "type": "array",
                          "items": {
                            "type": "object",
                            "additionalProperties": false,
                            "properties": {
                              "id": {
                                "type": "integer",
                                "minimum": 1
                              },
                              "title": {
                                "type": "string"
                              },
                              "version": {
                                "type": "integer",
                                "minimum": 1
                              },
                              "capability": {
                                "type": "string",
                                "enum": [
                                  "text"
                                ]
                              },
                              "consumer_module": {
                                "type": "string",
                                "maxLength": 96,
                                "pattern": "^(general|[a-z][a-z0-9-]*([.][a-z][a-z0-9-]*)+)$"
                              }
                            },
                            "required": [
                              "id",
                              "title",
                              "version",
                              "capability",
                              "consumer_module"
                            ]
                          }
                        },
                        "count": {
                          "type": "integer",
                          "minimum": 0
                        },
                        "page_size": {
                          "type": "integer",
                          "enum": [
                            50
                          ]
                        }
                      },
                      "required": [
                        "list",
                        "count",
                        "page_size"
                      ]
                    }
                  },
                  "required": [
                    "data"
                  ]
                }
              }
            }
          },
          "400": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "401": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "403": {
            "$ref": "#/components/responses/ErrorResponse"
          }
        }
      }
    },
    "/adminapi/custom.ai-assistant.generateText": {
      "post": {
        "operationId": "aiAssistantGenerateText",
        "tags": [
          "AiAssistant"
        ],
        "x-peanut-permissions": [
          "custom.ai-assistant.use"
        ],
        "description": "当前登录成员确认后将本次主题、提纲和路由模板交给所选文本服务，同成员同请求只能发送一次。候选由消费者明确采用。",
        "x-peanut-errors": [
          "AI_CONFIRM_SEND_REQUIRED",
          "AI_PERMISSION_DENIED",
          "AI_INPUT_INVALID",
          "AI_INPUT_LIMIT",
          "AI_ROUTE_SCOPE_DENIED",
          "AI_REQUEST_CONFLICT",
          "AI_QUOTA_EXCEEDED",
          "AI_CONFIGURATION_CHANGED",
          "AI_UNKNOWN_NO_RESEND",
          "AI_PROVIDER_REJECTED",
          "AI_PROVIDER_RESPONSE_UNUSABLE"
        ],
        "responses": {
          "200": {
            "description": "本次已保存或原请求已保存的文本候选",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "additionalProperties": false,
                  "properties": {
                    "code": {
                      "type": "integer"
                    },
                    "msg": {
                      "type": "string"
                    },
                    "data": {
                      "type": "object",
                      "additionalProperties": false,
                      "properties": {
                        "request_id": {
                          "type": "string",
                          "pattern": "^[A-Za-z0-9:_-]{1,100}$"
                        },
                        "candidate_id": {
                          "type": "integer",
                          "minimum": 1
                        },
                        "text": {
                          "type": "string"
                        }
                      },
                      "required": [
                        "request_id",
                        "candidate_id",
                        "text"
                      ]
                    },
                    "show": {
                      "type": "integer"
                    }
                  },
                  "required": [
                    "data"
                  ]
                }
              }
            }
          },
          "400": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "401": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "403": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "404": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "409": {
            "$ref": "#/components/responses/ErrorResponse"
          }
        },
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object",
                "additionalProperties": false,
                "properties": {
                  "route_id": {
                    "type": "integer",
                    "minimum": 1
                  },
                  "consumer_module": {
                    "type": "string",
                    "maxLength": 96,
                    "pattern": "^(general|[a-z][a-z0-9-]*([.][a-z][a-z0-9-]*)+)$"
                  },
                  "topic": {
                    "type": "string",
                    "maxLength": 2000,
                    "minLength": 1,
                    "writeOnly": true
                  },
                  "outline": {
                    "type": "string",
                    "maxLength": 4000,
                    "writeOnly": true
                  },
                  "request_id": {
                    "type": "string",
                    "pattern": "^[A-Za-z0-9:_-]{1,100}$"
                  },
                  "confirm_send": {
                    "type": "boolean",
                    "enum": [
                      true
                    ]
                  }
                },
                "required": [
                  "route_id",
                  "consumer_module",
                  "topic",
                  "request_id",
                  "confirm_send"
                ]
              }
            }
          }
        }
      }
    },
    "/adminapi/custom.ai-assistant.overview": {
      "get": {
        "operationId": "aiAssistantOverview",
        "tags": [
          "AiAssistant"
        ],
        "x-peanut-permissions": [
          "custom.ai-assistant.view"
        ],
        "x-peanut-errors": [
          "AI_INPUT_INVALID",
          "AI_PERMISSION_DENIED",
          "AI_NOT_FOUND",
          "AI_VERSION_CONFLICT",
          "AI_REQUEST_CONFLICT",
          "AI_SECRET_PROTECTOR_UNAVAILABLE",
          "AI_UNKNOWN_NO_RESEND",
          "SOURCE_AUTHORIZATION_BRIDGE_REQUIRED"
        ],
        "description": "每次核真实租户/成员/对象范围及Document固定来源授权；仅当实际Document实现未提供DocumentSourceGrants合同时返回SOURCE_AUTHORIZATION_BRIDGE_REQUIRED。已接入合同仍须通过当前来源授权、有效配置和明确发送确认，不以概览状态代替发送前检查。",
        "responses": {
          "200": {
            "description": "成功",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/AiObjectResponse"
                }
              }
            }
          },
          "400": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "401": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "403": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "404": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "409": {
            "$ref": "#/components/responses/ErrorResponse"
          }
        },
        "parameters": []
      }
    },
    "/adminapi/custom.ai-assistant.list": {
      "get": {
        "operationId": "aiAssistantList",
        "tags": [
          "AiAssistant"
        ],
        "x-peanut-permissions": [
          "custom.ai-assistant.view"
        ],
        "x-peanut-errors": [
          "AI_INPUT_INVALID",
          "AI_PERMISSION_DENIED",
          "AI_NOT_FOUND",
          "AI_VERSION_CONFLICT",
          "AI_REQUEST_CONFLICT",
          "AI_SECRET_PROTECTOR_UNAVAILABLE",
          "AI_UNKNOWN_NO_RESEND",
          "SOURCE_AUTHORIZATION_BRIDGE_REQUIRED"
        ],
        "description": "每次核真实租户/成员/对象范围及Document固定来源授权；仅当实际Document实现未提供DocumentSourceGrants合同时返回SOURCE_AUTHORIZATION_BRIDGE_REQUIRED。已接入合同仍须通过当前来源授权、有效配置和明确发送确认，不以概览状态代替发送前检查。",
        "responses": {
          "200": {
            "description": "成功",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/AiPageResponse"
                }
              }
            }
          },
          "400": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "401": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "403": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "404": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "409": {
            "$ref": "#/components/responses/ErrorResponse"
          }
        },
        "parameters": [
          {
            "name": "kind",
            "in": "query",
            "required": true,
            "schema": {
              "type": "string",
              "enum": [
                "connection",
                "model",
                "route",
                "template",
                "task",
                "usage"
              ]
            }
          },
          {
            "name": "page",
            "in": "query",
            "required": false,
            "schema": {
              "type": "integer",
              "minimum": 1
            }
          },
          {
            "name": "limit",
            "in": "query",
            "required": false,
            "schema": {
              "type": "integer",
              "minimum": 1,
              "maximum": 100
            }
          },
          {
            "name": "search",
            "in": "query",
            "required": false,
            "schema": {
              "type": "string",
              "maxLength": 200
            }
          },
          {
            "name": "status",
            "in": "query",
            "required": false,
            "schema": {
              "type": "string",
              "maxLength": 40
            }
          },
          {
            "name": "capability",
            "in": "query",
            "required": false,
            "schema": {
              "type": "string",
              "enum": [
                "text",
                "image",
                "speech",
                "transcription",
                "video"
              ]
            }
          }
        ]
      }
    },
    "/adminapi/custom.ai-assistant.detail": {
      "get": {
        "operationId": "aiAssistantDetail",
        "tags": [
          "AiAssistant"
        ],
        "x-peanut-permissions": [
          "custom.ai-assistant.view"
        ],
        "x-peanut-errors": [
          "AI_INPUT_INVALID",
          "AI_PERMISSION_DENIED",
          "AI_NOT_FOUND",
          "AI_VERSION_CONFLICT",
          "AI_REQUEST_CONFLICT",
          "AI_SECRET_PROTECTOR_UNAVAILABLE",
          "AI_UNKNOWN_NO_RESEND",
          "SOURCE_AUTHORIZATION_BRIDGE_REQUIRED"
        ],
        "description": "每次核真实租户/成员/对象范围及Document固定来源授权；仅当实际Document实现未提供DocumentSourceGrants合同时返回SOURCE_AUTHORIZATION_BRIDGE_REQUIRED。已接入合同仍须通过当前来源授权、有效配置和明确发送确认，不以概览状态代替发送前检查。",
        "responses": {
          "200": {
            "description": "成功",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/AiRecordResponse"
                }
              }
            }
          },
          "400": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "401": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "403": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "404": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "409": {
            "$ref": "#/components/responses/ErrorResponse"
          }
        },
        "parameters": [
          {
            "name": "kind",
            "in": "query",
            "required": true,
            "schema": {
              "type": "string",
              "enum": [
                "connection",
                "model",
                "route",
                "template",
                "task",
                "usage"
              ]
            }
          },
          {
            "name": "id",
            "in": "query",
            "required": true,
            "schema": {
              "type": "integer",
              "minimum": 1
            }
          }
        ]
      }
    },
    "/adminapi/custom.ai-assistant.history": {
      "get": {
        "operationId": "aiAssistantHistory",
        "tags": [
          "AiAssistant"
        ],
        "x-peanut-permissions": [
          "custom.ai-assistant.view"
        ],
        "x-peanut-errors": [
          "AI_INPUT_INVALID",
          "AI_PERMISSION_DENIED",
          "AI_NOT_FOUND",
          "AI_VERSION_CONFLICT",
          "AI_REQUEST_CONFLICT",
          "AI_SECRET_PROTECTOR_UNAVAILABLE",
          "AI_UNKNOWN_NO_RESEND",
          "SOURCE_AUTHORIZATION_BRIDGE_REQUIRED"
        ],
        "description": "每次核真实租户/成员/对象范围及Document固定来源授权；仅当实际Document实现未提供DocumentSourceGrants合同时返回SOURCE_AUTHORIZATION_BRIDGE_REQUIRED。已接入合同仍须通过当前来源授权、有效配置和明确发送确认，不以概览状态代替发送前检查。",
        "responses": {
          "200": {
            "description": "成功",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/AiHistoryResponse"
                }
              }
            }
          },
          "400": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "401": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "403": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "404": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "409": {
            "$ref": "#/components/responses/ErrorResponse"
          }
        },
        "parameters": [
          {
            "name": "kind",
            "in": "query",
            "required": true,
            "schema": {
              "type": "string",
              "enum": [
                "connection",
                "model",
                "route",
                "template",
                "task",
                "usage"
              ]
            }
          },
          {
            "name": "id",
            "in": "query",
            "required": true,
            "schema": {
              "type": "integer",
              "minimum": 1
            }
          },
          {
            "name": "before_id",
            "in": "query",
            "required": false,
            "schema": {
              "type": "integer",
              "minimum": 1
            }
          },
          {
            "name": "limit",
            "in": "query",
            "required": false,
            "schema": {
              "type": "integer",
              "minimum": 1,
              "maximum": 100
            }
          }
        ]
      }
    },
    "/adminapi/custom.ai-assistant.lookups": {
      "get": {
        "operationId": "aiAssistantLookups",
        "tags": [
          "AiAssistant"
        ],
        "x-peanut-permissions": [
          "custom.ai-assistant.view"
        ],
        "x-peanut-errors": [
          "AI_INPUT_INVALID",
          "AI_PERMISSION_DENIED",
          "AI_NOT_FOUND",
          "AI_VERSION_CONFLICT",
          "AI_REQUEST_CONFLICT",
          "AI_SECRET_PROTECTOR_UNAVAILABLE",
          "AI_UNKNOWN_NO_RESEND",
          "SOURCE_AUTHORIZATION_BRIDGE_REQUIRED"
        ],
        "description": "每次核真实租户/成员/对象范围及Document固定来源授权；仅当实际Document实现未提供DocumentSourceGrants合同时返回SOURCE_AUTHORIZATION_BRIDGE_REQUIRED。已接入合同仍须通过当前来源授权、有效配置和明确发送确认，不以概览状态代替发送前检查。",
        "responses": {
          "200": {
            "description": "成功",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/AiObjectResponse"
                }
              }
            }
          },
          "400": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "401": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "403": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "404": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "409": {
            "$ref": "#/components/responses/ErrorResponse"
          }
        },
        "parameters": [
          {
            "name": "kind",
            "in": "query",
            "required": true,
            "schema": {
              "type": "string",
              "enum": [
                "connection",
                "model",
                "route",
                "template"
              ]
            }
          },
          {
            "name": "operation",
            "in": "query",
            "required": false,
            "schema": {
              "type": "string",
              "enum": [
                "use",
                "configure"
              ]
            }
          },
          {
            "name": "page",
            "in": "query",
            "required": false,
            "schema": {
              "type": "integer",
              "minimum": 1
            }
          },
          {
            "name": "search",
            "in": "query",
            "required": false,
            "schema": {
              "type": "string",
              "maxLength": 200
            }
          }
        ]
      }
    },
    "/adminapi/custom.ai-assistant.saveConfiguration": {
      "post": {
        "operationId": "aiAssistantSaveConfiguration",
        "tags": [
          "AiAssistant"
        ],
        "x-peanut-permissions": [
          "custom.ai-assistant.configure"
        ],
        "x-peanut-errors": [
          "AI_INPUT_INVALID",
          "AI_PERMISSION_DENIED",
          "AI_NOT_FOUND",
          "AI_VERSION_CONFLICT",
          "AI_REQUEST_CONFLICT",
          "AI_SECRET_PROTECTOR_UNAVAILABLE",
          "AI_UNKNOWN_NO_RESEND",
          "SOURCE_AUTHORIZATION_BRIDGE_REQUIRED"
        ],
        "description": "每次核真实租户/成员/对象范围及Document固定来源授权；仅当实际Document实现未提供DocumentSourceGrants合同时返回SOURCE_AUTHORIZATION_BRIDGE_REQUIRED。已接入合同仍须通过当前来源授权、有效配置和明确发送确认，不以概览状态代替发送前检查。",
        "responses": {
          "200": {
            "description": "成功",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/AiRecordResponse"
                }
              }
            }
          },
          "400": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "401": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "403": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "404": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "409": {
            "$ref": "#/components/responses/ErrorResponse"
          }
        },
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "oneOf": [
                  {
                    "type": "object",
                    "additionalProperties": false,
                    "properties": {
                      "kind": {
                        "type": "string",
                        "enum": [
                          "connection"
                        ]
                      },
                      "title": {
                        "type": "string",
                        "minLength": 1,
                        "maxLength": 200
                      },
                      "data": {
                        "type": "object",
                        "additionalProperties": false,
                        "properties": {
                          "protocol": {
                            "type": "string",
                            "enum": [
                              "openai_text",
                              "anthropic_messages",
                              "openai_image",
                              "openai_speech",
                              "openai_transcription",
                              "gemini_video"
                            ]
                          },
                          "origin": {
                            "type": "string",
                            "minLength": 1,
                            "maxLength": 2000
                          },
                          "api_prefix": {
                            "type": "string",
                            "minLength": 1,
                            "maxLength": 2000
                          },
                          "responsibility": {
                            "type": "string",
                            "enum": [
                              "self_deployed",
                              "institution_provider",
                              "platform_service"
                            ]
                          },
                          "responsibility_note": {
                            "type": "string",
                            "minLength": 1,
                            "maxLength": 2000
                          },
                          "daily_request_limit": {
                            "type": "integer",
                            "minimum": 1,
                            "maximum": 100000
                          }
                        },
                        "required": [
                          "protocol",
                          "origin",
                          "api_prefix",
                          "responsibility",
                          "responsibility_note",
                          "daily_request_limit"
                        ]
                      },
                      "request_id": {
                        "type": "string",
                        "pattern": "^[A-Za-z0-9:_-]{1,100}$"
                      }
                    },
                    "required": [
                      "kind",
                      "title",
                      "data",
                      "request_id"
                    ]
                  },
                  {
                    "type": "object",
                    "additionalProperties": false,
                    "properties": {
                      "kind": {
                        "type": "string",
                        "enum": [
                          "connection"
                        ]
                      },
                      "title": {
                        "type": "string",
                        "minLength": 1,
                        "maxLength": 200
                      },
                      "data": {
                        "type": "object",
                        "additionalProperties": false,
                        "properties": {
                          "protocol": {
                            "type": "string",
                            "enum": [
                              "openai_text",
                              "anthropic_messages",
                              "openai_image",
                              "openai_speech",
                              "openai_transcription",
                              "gemini_video"
                            ]
                          },
                          "origin": {
                            "type": "string",
                            "minLength": 1,
                            "maxLength": 2000
                          },
                          "api_prefix": {
                            "type": "string",
                            "minLength": 1,
                            "maxLength": 2000
                          },
                          "responsibility": {
                            "type": "string",
                            "enum": [
                              "self_deployed",
                              "institution_provider",
                              "platform_service"
                            ]
                          },
                          "responsibility_note": {
                            "type": "string",
                            "minLength": 1,
                            "maxLength": 2000
                          },
                          "daily_request_limit": {
                            "type": "integer",
                            "minimum": 1,
                            "maximum": 100000
                          }
                        },
                        "required": [
                          "protocol",
                          "origin",
                          "api_prefix",
                          "responsibility",
                          "responsibility_note",
                          "daily_request_limit"
                        ]
                      },
                      "request_id": {
                        "type": "string",
                        "pattern": "^[A-Za-z0-9:_-]{1,100}$"
                      },
                      "id": {
                        "type": "integer",
                        "minimum": 1
                      },
                      "version": {
                        "type": "integer",
                        "minimum": 1
                      }
                    },
                    "required": [
                      "kind",
                      "title",
                      "data",
                      "request_id",
                      "id",
                      "version"
                    ]
                  },
                  {
                    "type": "object",
                    "additionalProperties": false,
                    "properties": {
                      "kind": {
                        "type": "string",
                        "enum": [
                          "model"
                        ]
                      },
                      "title": {
                        "type": "string",
                        "minLength": 1,
                        "maxLength": 200
                      },
                      "data": {
                        "type": "object",
                        "additionalProperties": false,
                        "properties": {
                          "connection_id": {
                            "type": "integer",
                            "minimum": 1
                          },
                          "provider_model_id": {
                            "type": "string",
                            "maxLength": 160
                          },
                          "capability": {
                            "type": "string",
                            "enum": [
                              "text",
                              "image",
                              "speech",
                              "transcription",
                              "video"
                            ]
                          },
                          "input_limit_chars": {
                            "type": "integer",
                            "minimum": 1,
                            "maximum": 32000
                          },
                          "max_output_tokens": {
                            "type": "integer",
                            "minimum": 1,
                            "maximum": 16000
                          },
                          "voice": {
                            "type": "string",
                            "maxLength": 200
                          },
                          "valid_until": {
                            "type": "string",
                            "format": "date-time"
                          },
                          "supports_background": {
                            "type": "boolean"
                          }
                        },
                        "required": [
                          "connection_id",
                          "provider_model_id",
                          "capability",
                          "input_limit_chars",
                          "max_output_tokens",
                          "supports_background"
                        ]
                      },
                      "request_id": {
                        "type": "string",
                        "pattern": "^[A-Za-z0-9:_-]{1,100}$"
                      }
                    },
                    "required": [
                      "kind",
                      "title",
                      "data",
                      "request_id"
                    ]
                  },
                  {
                    "type": "object",
                    "additionalProperties": false,
                    "properties": {
                      "kind": {
                        "type": "string",
                        "enum": [
                          "model"
                        ]
                      },
                      "title": {
                        "type": "string",
                        "minLength": 1,
                        "maxLength": 200
                      },
                      "data": {
                        "type": "object",
                        "additionalProperties": false,
                        "properties": {
                          "connection_id": {
                            "type": "integer",
                            "minimum": 1
                          },
                          "provider_model_id": {
                            "type": "string",
                            "maxLength": 160
                          },
                          "capability": {
                            "type": "string",
                            "enum": [
                              "text",
                              "image",
                              "speech",
                              "transcription",
                              "video"
                            ]
                          },
                          "input_limit_chars": {
                            "type": "integer",
                            "minimum": 1,
                            "maximum": 32000
                          },
                          "max_output_tokens": {
                            "type": "integer",
                            "minimum": 1,
                            "maximum": 16000
                          },
                          "voice": {
                            "type": "string",
                            "maxLength": 200
                          },
                          "valid_until": {
                            "type": "string",
                            "format": "date-time"
                          },
                          "supports_background": {
                            "type": "boolean"
                          }
                        },
                        "required": [
                          "connection_id",
                          "provider_model_id",
                          "capability",
                          "input_limit_chars",
                          "max_output_tokens",
                          "supports_background"
                        ]
                      },
                      "request_id": {
                        "type": "string",
                        "pattern": "^[A-Za-z0-9:_-]{1,100}$"
                      },
                      "id": {
                        "type": "integer",
                        "minimum": 1
                      },
                      "version": {
                        "type": "integer",
                        "minimum": 1
                      }
                    },
                    "required": [
                      "kind",
                      "title",
                      "data",
                      "request_id",
                      "id",
                      "version"
                    ]
                  },
                  {
                    "type": "object",
                    "additionalProperties": false,
                    "properties": {
                      "kind": {
                        "type": "string",
                        "enum": [
                          "route"
                        ]
                      },
                      "title": {
                        "type": "string",
                        "minLength": 1,
                        "maxLength": 200
                      },
                      "data": {
                        "type": "object",
                        "additionalProperties": false,
                        "properties": {
                          "capability": {
                            "type": "string",
                            "enum": [
                              "text",
                              "image",
                              "speech",
                              "transcription",
                              "video"
                            ]
                          },
                          "model_id": {
                            "type": "integer",
                            "minimum": 1
                          },
                          "template_id": {
                            "type": "integer",
                            "minimum": 1
                          },
                          "consumer_module": {
                            "type": "string",
                            "maxLength": 96,
                            "pattern": "^(general|[a-z][a-z0-9-]*([.][a-z][a-z0-9-]*)+)$"
                          },
                          "scope_note": {
                            "type": "string",
                            "minLength": 1,
                            "maxLength": 2000
                          }
                        },
                        "required": [
                          "capability",
                          "model_id",
                          "template_id",
                          "consumer_module",
                          "scope_note"
                        ]
                      },
                      "request_id": {
                        "type": "string",
                        "pattern": "^[A-Za-z0-9:_-]{1,100}$"
                      }
                    },
                    "required": [
                      "kind",
                      "title",
                      "data",
                      "request_id"
                    ]
                  },
                  {
                    "type": "object",
                    "additionalProperties": false,
                    "properties": {
                      "kind": {
                        "type": "string",
                        "enum": [
                          "route"
                        ]
                      },
                      "title": {
                        "type": "string",
                        "minLength": 1,
                        "maxLength": 200
                      },
                      "data": {
                        "type": "object",
                        "additionalProperties": false,
                        "properties": {
                          "capability": {
                            "type": "string",
                            "enum": [
                              "text",
                              "image",
                              "speech",
                              "transcription",
                              "video"
                            ]
                          },
                          "model_id": {
                            "type": "integer",
                            "minimum": 1
                          },
                          "template_id": {
                            "type": "integer",
                            "minimum": 1
                          },
                          "consumer_module": {
                            "type": "string",
                            "maxLength": 96,
                            "pattern": "^(general|[a-z][a-z0-9-]*([.][a-z][a-z0-9-]*)+)$"
                          },
                          "scope_note": {
                            "type": "string",
                            "minLength": 1,
                            "maxLength": 2000
                          }
                        },
                        "required": [
                          "capability",
                          "model_id",
                          "template_id",
                          "consumer_module",
                          "scope_note"
                        ]
                      },
                      "request_id": {
                        "type": "string",
                        "pattern": "^[A-Za-z0-9:_-]{1,100}$"
                      },
                      "id": {
                        "type": "integer",
                        "minimum": 1
                      },
                      "version": {
                        "type": "integer",
                        "minimum": 1
                      }
                    },
                    "required": [
                      "kind",
                      "title",
                      "data",
                      "request_id",
                      "id",
                      "version"
                    ]
                  },
                  {
                    "type": "object",
                    "additionalProperties": false,
                    "properties": {
                      "kind": {
                        "type": "string",
                        "enum": [
                          "template"
                        ]
                      },
                      "title": {
                        "type": "string",
                        "minLength": 1,
                        "maxLength": 200
                      },
                      "data": {
                        "type": "object",
                        "additionalProperties": false,
                        "properties": {
                          "capability": {
                            "type": "string",
                            "enum": [
                              "text",
                              "image",
                              "speech",
                              "transcription",
                              "video"
                            ]
                          },
                          "instructions": {
                            "type": "string",
                            "minLength": 1,
                            "maxLength": 16000
                          },
                          "scope_note": {
                            "type": "string",
                            "minLength": 1,
                            "maxLength": 2000
                          }
                        },
                        "required": [
                          "capability",
                          "instructions",
                          "scope_note"
                        ]
                      },
                      "request_id": {
                        "type": "string",
                        "pattern": "^[A-Za-z0-9:_-]{1,100}$"
                      }
                    },
                    "required": [
                      "kind",
                      "title",
                      "data",
                      "request_id"
                    ]
                  },
                  {
                    "type": "object",
                    "additionalProperties": false,
                    "properties": {
                      "kind": {
                        "type": "string",
                        "enum": [
                          "template"
                        ]
                      },
                      "title": {
                        "type": "string",
                        "minLength": 1,
                        "maxLength": 200
                      },
                      "data": {
                        "type": "object",
                        "additionalProperties": false,
                        "properties": {
                          "capability": {
                            "type": "string",
                            "enum": [
                              "text",
                              "image",
                              "speech",
                              "transcription",
                              "video"
                            ]
                          },
                          "instructions": {
                            "type": "string",
                            "minLength": 1,
                            "maxLength": 16000
                          },
                          "scope_note": {
                            "type": "string",
                            "minLength": 1,
                            "maxLength": 2000
                          }
                        },
                        "required": [
                          "capability",
                          "instructions",
                          "scope_note"
                        ]
                      },
                      "request_id": {
                        "type": "string",
                        "pattern": "^[A-Za-z0-9:_-]{1,100}$"
                      },
                      "id": {
                        "type": "integer",
                        "minimum": 1
                      },
                      "version": {
                        "type": "integer",
                        "minimum": 1
                      }
                    },
                    "required": [
                      "kind",
                      "title",
                      "data",
                      "request_id",
                      "id",
                      "version"
                    ]
                  }
                ]
              }
            }
          }
        }
      }
    },
    "/adminapi/custom.ai-assistant.saveCredential": {
      "post": {
        "operationId": "aiAssistantSaveCredential",
        "tags": [
          "AiAssistant"
        ],
        "x-peanut-permissions": [
          "custom.ai-assistant.configure"
        ],
        "x-peanut-errors": [
          "AI_INPUT_INVALID",
          "AI_PERMISSION_DENIED",
          "AI_NOT_FOUND",
          "AI_VERSION_CONFLICT",
          "AI_REQUEST_CONFLICT",
          "AI_SECRET_PROTECTOR_UNAVAILABLE",
          "AI_UNKNOWN_NO_RESEND",
          "SOURCE_AUTHORIZATION_BRIDGE_REQUIRED"
        ],
        "description": "仅写入SecretProtector认证密文，绑定当前tenant/真实connection；响应仅已配置状态，密钥环不可用拒绝，无明文回显或请求体通用日志。",
        "responses": {
          "200": {
            "description": "成功",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/AiRecordResponse"
                }
              }
            }
          },
          "400": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "401": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "403": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "404": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "409": {
            "$ref": "#/components/responses/ErrorResponse"
          }
        },
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object",
                "additionalProperties": false,
                "properties": {
                  "id": {
                    "type": "integer",
                    "minimum": 1
                  },
                  "version": {
                    "type": "integer",
                    "minimum": 1
                  },
                  "secret": {
                    "type": "string",
                    "minLength": 8,
                    "maxLength": 8192,
                    "writeOnly": true
                  },
                  "request_id": {
                    "type": "string",
                    "pattern": "^[A-Za-z0-9:_-]{1,100}$"
                  }
                },
                "required": [
                  "id",
                  "version",
                  "secret",
                  "request_id"
                ]
              }
            }
          }
        }
      }
    },
    "/adminapi/custom.ai-assistant.configurationState": {
      "post": {
        "operationId": "aiAssistantConfigurationState",
        "tags": [
          "AiAssistant"
        ],
        "x-peanut-permissions": [
          "custom.ai-assistant.configure"
        ],
        "x-peanut-errors": [
          "AI_INPUT_INVALID",
          "AI_PERMISSION_DENIED",
          "AI_NOT_FOUND",
          "AI_VERSION_CONFLICT",
          "AI_REQUEST_CONFLICT",
          "AI_SECRET_PROTECTOR_UNAVAILABLE",
          "AI_UNKNOWN_NO_RESEND",
          "SOURCE_AUTHORIZATION_BRIDGE_REQUIRED"
        ],
        "description": "每次核真实租户/成员/对象范围及Document固定来源授权；仅当实际Document实现未提供DocumentSourceGrants合同时返回SOURCE_AUTHORIZATION_BRIDGE_REQUIRED。已接入合同仍须通过当前来源授权、有效配置和明确发送确认，不以概览状态代替发送前检查。",
        "responses": {
          "200": {
            "description": "成功",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/AiRecordResponse"
                }
              }
            }
          },
          "400": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "401": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "403": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "404": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "409": {
            "$ref": "#/components/responses/ErrorResponse"
          }
        },
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object",
                "additionalProperties": false,
                "properties": {
                  "kind": {
                    "type": "string",
                    "enum": [
                      "connection",
                      "model",
                      "route",
                      "template"
                    ]
                  },
                  "id": {
                    "type": "integer",
                    "minimum": 1
                  },
                  "version": {
                    "type": "integer",
                    "minimum": 1
                  },
                  "action": {
                    "type": "string",
                    "enum": [
                      "enable",
                      "disable"
                    ]
                  },
                  "reason": {
                    "type": "string",
                    "minLength": 1,
                    "maxLength": 2000
                  },
                  "request_id": {
                    "type": "string",
                    "pattern": "^[A-Za-z0-9:_-]{1,100}$"
                  }
                },
                "required": [
                  "kind",
                  "id",
                  "version",
                  "action",
                  "reason",
                  "request_id"
                ]
              }
            }
          }
        }
      }
    },
    "/adminapi/custom.ai-assistant.createTask": {
      "post": {
        "operationId": "aiAssistantCreateTask",
        "tags": [
          "AiAssistant"
        ],
        "x-peanut-permissions": [
          "custom.ai-assistant.use"
        ],
        "x-peanut-errors": [
          "AI_INPUT_INVALID",
          "AI_PERMISSION_DENIED",
          "AI_NOT_FOUND",
          "AI_VERSION_CONFLICT",
          "AI_REQUEST_CONFLICT",
          "AI_SECRET_PROTECTOR_UNAVAILABLE",
          "AI_UNKNOWN_NO_RESEND",
          "SOURCE_AUTHORIZATION_BRIDGE_REQUIRED"
        ],
        "description": "先回读同request的既有任务；固定配置及Document版本准备在事务外，原生证明在CAS事务内关联。POST外发当前严格阻断；未知另发须确认可能重复消耗。",
        "responses": {
          "200": {
            "description": "成功",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/AiRecordResponse"
                }
              }
            }
          },
          "400": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "401": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "403": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "404": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "409": {
            "$ref": "#/components/responses/ErrorResponse"
          }
        },
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object",
                "additionalProperties": false,
                "properties": {
                  "title": {
                    "type": "string",
                    "minLength": 1,
                    "maxLength": 200
                  },
                  "route_id": {
                    "type": "integer",
                    "minimum": 1
                  },
                  "document_id": {
                    "type": "integer",
                    "minimum": 1
                  },
                  "revision_id": {
                    "type": "integer",
                    "minimum": 1
                  },
                  "instruction": {
                    "type": "string",
                    "maxLength": 4000,
                    "writeOnly": true
                  },
                  "consumer_module": {
                    "type": "string",
                    "maxLength": 96,
                    "pattern": "^(general|[a-z][a-z0-9-]*([.][a-z][a-z0-9-]*)+)$"
                  },
                  "request_id": {
                    "type": "string",
                    "pattern": "^[A-Za-z0-9:_-]{1,100}$"
                  },
                  "repeat_of_task_id": {
                    "type": "integer",
                    "minimum": 1
                  },
                  "confirm_possible_duplicate_cost": {
                    "type": "boolean"
                  }
                },
                "required": [
                  "title",
                  "route_id",
                  "document_id",
                  "revision_id",
                  "consumer_module",
                  "request_id"
                ]
              }
            }
          }
        }
      }
    },
    "/adminapi/custom.ai-assistant.taskStatus": {
      "get": {
        "operationId": "aiAssistantTaskStatus",
        "tags": [
          "AiAssistant"
        ],
        "x-peanut-permissions": [
          "custom.ai-assistant.view"
        ],
        "x-peanut-errors": [
          "AI_INPUT_INVALID",
          "AI_PERMISSION_DENIED",
          "AI_NOT_FOUND",
          "AI_VERSION_CONFLICT",
          "AI_REQUEST_CONFLICT",
          "AI_SECRET_PROTECTOR_UNAVAILABLE",
          "AI_UNKNOWN_NO_RESEND",
          "SOURCE_AUTHORIZATION_BRIDGE_REQUIRED"
        ],
        "description": "每次核真实租户/成员/对象范围及Document固定来源授权；仅当实际Document实现未提供DocumentSourceGrants合同时返回SOURCE_AUTHORIZATION_BRIDGE_REQUIRED。已接入合同仍须通过当前来源授权、有效配置和明确发送确认，不以概览状态代替发送前检查。",
        "responses": {
          "200": {
            "description": "成功",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/AiRecordResponse"
                }
              }
            }
          },
          "400": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "401": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "403": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "404": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "409": {
            "$ref": "#/components/responses/ErrorResponse"
          }
        },
        "parameters": [
          {
            "name": "id",
            "in": "query",
            "required": true,
            "schema": {
              "type": "integer",
              "minimum": 1
            }
          }
        ]
      }
    },
    "/adminapi/custom.ai-assistant.taskAction": {
      "post": {
        "operationId": "aiAssistantTaskAction",
        "tags": [
          "AiAssistant"
        ],
        "x-peanut-permissions": [
          "custom.ai-assistant.use"
        ],
        "x-peanut-errors": [
          "AI_INPUT_INVALID",
          "AI_PERMISSION_DENIED",
          "AI_NOT_FOUND",
          "AI_VERSION_CONFLICT",
          "AI_REQUEST_CONFLICT",
          "AI_SECRET_PROTECTOR_UNAVAILABLE",
          "AI_UNKNOWN_NO_RESEND",
          "SOURCE_AUTHORIZATION_BRIDGE_REQUIRED"
        ],
        "description": "原生Task read/manage独立权限。取消不冒称远端取消/退款；未知POST无已确认同job回执不能重发；有真实Gemini interaction ID才允许GET核对。",
        "responses": {
          "200": {
            "description": "成功",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/AiRecordResponse"
                }
              }
            }
          },
          "400": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "401": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "403": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "404": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "409": {
            "$ref": "#/components/responses/ErrorResponse"
          }
        },
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object",
                "additionalProperties": false,
                "properties": {
                  "id": {
                    "type": "integer",
                    "minimum": 1
                  },
                  "action": {
                    "type": "string",
                    "enum": [
                      "cancel",
                      "retry",
                      "observeUnknown",
                      "poll"
                    ]
                  },
                  "job_revision": {
                    "type": "integer",
                    "minimum": 1
                  },
                  "version": {
                    "type": "integer",
                    "minimum": 1
                  },
                  "request_id": {
                    "type": "string",
                    "pattern": "^[A-Za-z0-9:_-]{1,100}$"
                  }
                },
                "required": [
                  "id",
                  "action",
                  "request_id"
                ],
                "oneOf": [
                  {
                    "required": [
                      "job_revision"
                    ],
                    "not": {
                      "required": [
                        "version"
                      ]
                    }
                  },
                  {
                    "required": [
                      "version"
                    ],
                    "properties": {
                      "action": {
                        "enum": [
                          "cancel"
                        ]
                      }
                    },
                    "not": {
                      "required": [
                        "job_revision"
                      ]
                    }
                  }
                ]
              }
            }
          }
        }
      }
    },
    "/adminapi/custom.ai-assistant.collectCandidate": {
      "post": {
        "operationId": "aiAssistantCollectCandidate",
        "tags": [
          "AiAssistant"
        ],
        "x-peanut-permissions": [
          "custom.ai-assistant.use"
        ],
        "x-peanut-errors": [
          "AI_INPUT_INVALID",
          "AI_PERMISSION_DENIED",
          "AI_NOT_FOUND",
          "AI_VERSION_CONFLICT",
          "AI_REQUEST_CONFLICT",
          "AI_SECRET_PROTECTOR_UNAVAILABLE",
          "AI_UNKNOWN_NO_RESEND",
          "SOURCE_AUTHORIZATION_BRIDGE_REQUIRED"
        ],
        "description": "实际Admin当前源Document/File、配置及任务范围重新授权后领取；必须原生Task succeeded和真实私有候选。输出MIME/SHA/尺寸复核，真实File/Document固定，未知写入同请求恢复不盲重传。",
        "responses": {
          "200": {
            "description": "成功",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/AiRecordResponse"
                }
              }
            }
          },
          "400": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "401": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "403": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "404": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "409": {
            "$ref": "#/components/responses/ErrorResponse"
          }
        },
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object",
                "additionalProperties": false,
                "properties": {
                  "id": {
                    "type": "integer",
                    "minimum": 1
                  },
                  "version": {
                    "type": "integer",
                    "minimum": 1
                  },
                  "request_id": {
                    "type": "string",
                    "pattern": "^[A-Za-z0-9:_-]{1,100}$"
                  },
                  "recovered_file_key": {
                    "type": "string",
                    "pattern": "^file_[a-f0-9]{32}$"
                  }
                },
                "required": [
                  "id",
                  "version",
                  "request_id"
                ]
              }
            }
          }
        }
      }
    },
    "/adminapi/custom.ai-assistant.candidate": {
      "get": {
        "operationId": "aiAssistantCandidate",
        "tags": [
          "AiAssistant"
        ],
        "x-peanut-permissions": [
          "custom.ai-assistant.view"
        ],
        "x-peanut-errors": [
          "AI_INPUT_INVALID",
          "AI_PERMISSION_DENIED",
          "AI_NOT_FOUND",
          "AI_VERSION_CONFLICT",
          "AI_REQUEST_CONFLICT",
          "AI_SECRET_PROTECTOR_UNAVAILABLE",
          "AI_UNKNOWN_NO_RESEND",
          "SOURCE_AUTHORIZATION_BRIDGE_REQUIRED"
        ],
        "description": "实际Admin当前源与配置授权下比较固定来源/已领取候选；纯文本规范化，不执行工具或HTML，不自动审批发布。",
        "responses": {
          "200": {
            "description": "成功",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/AiObjectResponse"
                }
              }
            }
          },
          "400": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "401": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "403": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "404": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "409": {
            "$ref": "#/components/responses/ErrorResponse"
          }
        },
        "parameters": [
          {
            "name": "id",
            "in": "query",
            "required": true,
            "schema": {
              "type": "integer",
              "minimum": 1
            }
          }
        ]
      }
    },
    "/adminapi/custom.ai-assistant.decide": {
      "post": {
        "operationId": "aiAssistantDecide",
        "tags": [
          "AiAssistant"
        ],
        "x-peanut-permissions": [
          "custom.ai-assistant.review"
        ],
        "x-peanut-errors": [
          "AI_INPUT_INVALID",
          "AI_PERMISSION_DENIED",
          "AI_NOT_FOUND",
          "AI_VERSION_CONFLICT",
          "AI_REQUEST_CONFLICT",
          "AI_SECRET_PROTECTOR_UNAVAILABLE",
          "AI_UNKNOWN_NO_RESEND",
          "SOURCE_AUTHORIZATION_BRIDGE_REQUIRED"
        ],
        "description": "真实人员按CAS留下接受/退回理由与固定结果；不更新新闻正文、业务审批或自动发布。",
        "responses": {
          "200": {
            "description": "成功",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/AiRecordResponse"
                }
              }
            }
          },
          "400": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "401": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "403": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "404": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "409": {
            "$ref": "#/components/responses/ErrorResponse"
          }
        },
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object",
                "additionalProperties": false,
                "properties": {
                  "id": {
                    "type": "integer",
                    "minimum": 1
                  },
                  "version": {
                    "type": "integer",
                    "minimum": 1
                  },
                  "decision": {
                    "type": "string",
                    "enum": [
                      "accept",
                      "return"
                    ]
                  },
                  "reason": {
                    "type": "string",
                    "minLength": 1,
                    "maxLength": 4000
                  },
                  "request_id": {
                    "type": "string",
                    "pattern": "^[A-Za-z0-9:_-]{1,100}$"
                  }
                },
                "required": [
                  "id",
                  "version",
                  "decision",
                  "reason",
                  "request_id"
                ]
              }
            }
          }
        }
      }
    },
    "/adminapi/custom.ai-assistant.resolve": {
      "post": {
        "operationId": "aiAssistantResolve",
        "tags": [
          "AiAssistant"
        ],
        "x-peanut-permissions": [
          "custom.ai-assistant.view"
        ],
        "x-peanut-errors": [
          "AI_INPUT_INVALID",
          "AI_PERMISSION_DENIED",
          "AI_NOT_FOUND",
          "AI_VERSION_CONFLICT",
          "AI_REQUEST_CONFLICT",
          "AI_SECRET_PROTECTOR_UNAVAILABLE",
          "AI_UNKNOWN_NO_RESEND",
          "SOURCE_AUTHORIZATION_BRIDGE_REQUIRED"
        ],
        "description": "每次核真实租户/成员/对象范围及Document固定来源授权；仅当实际Document实现未提供DocumentSourceGrants合同时返回SOURCE_AUTHORIZATION_BRIDGE_REQUIRED。已接入合同仍须通过当前来源授权、有效配置和明确发送确认，不以概览状态代替发送前检查。",
        "responses": {
          "200": {
            "description": "成功",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/AiObjectResponse"
                }
              }
            }
          },
          "400": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "401": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "403": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "404": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "409": {
            "$ref": "#/components/responses/ErrorResponse"
          }
        },
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object",
                "additionalProperties": false,
                "properties": {
                  "id": {
                    "type": "integer",
                    "minimum": 1
                  },
                  "operation": {
                    "type": "string",
                    "enum": [
                      "preview",
                      "download_original"
                    ]
                  }
                },
                "required": [
                  "id",
                  "operation"
                ]
              }
            }
          }
        }
      }
    },
    "/adminapi/custom.ai-assistant.manualCandidate": {
      "post": {
        "operationId": "aiAssistantManualCandidate",
        "tags": [
          "AiAssistant"
        ],
        "x-peanut-permissions": [
          "custom.ai-assistant.use"
        ],
        "x-peanut-errors": [
          "AI_INPUT_INVALID",
          "AI_PERMISSION_DENIED",
          "AI_NOT_FOUND",
          "AI_VERSION_CONFLICT",
          "AI_REQUEST_CONFLICT",
          "AI_SECRET_PROTECTOR_UNAVAILABLE",
          "AI_UNKNOWN_NO_RESEND",
          "SOURCE_AUTHORIZATION_BRIDGE_REQUIRED"
        ],
        "description": "人工提供的文字/PNG/WAV/MP4，当前Admin真实File授权和固定源复核。另一个原生任务仅本地验证真实字节，标记manual_text_entry/manual_upload与provider_verified=false；不调用模型，不把原未知调用改成功。",
        "responses": {
          "200": {
            "description": "成功",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/AiRecordResponse"
                }
              }
            }
          },
          "400": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "401": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "403": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "404": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "409": {
            "$ref": "#/components/responses/ErrorResponse"
          }
        },
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "oneOf": [
                  {
                    "type": "object",
                    "additionalProperties": false,
                    "properties": {
                      "id": {
                        "type": "integer",
                        "minimum": 1
                      },
                      "version": {
                        "type": "integer",
                        "minimum": 1
                      },
                      "result_type": {
                        "type": "string",
                        "enum": [
                          "text",
                          "transcript"
                        ]
                      },
                      "text": {
                        "type": "string",
                        "minLength": 1,
                        "maxLength": 1000000,
                        "writeOnly": true
                      },
                      "reason": {
                        "type": "string",
                        "minLength": 1,
                        "maxLength": 4000
                      },
                      "request_id": {
                        "type": "string",
                        "pattern": "^[A-Za-z0-9:_-]{1,100}$"
                      }
                    },
                    "required": [
                      "id",
                      "version",
                      "result_type",
                      "text",
                      "reason",
                      "request_id"
                    ]
                  },
                  {
                    "type": "object",
                    "additionalProperties": false,
                    "properties": {
                      "id": {
                        "type": "integer",
                        "minimum": 1
                      },
                      "version": {
                        "type": "integer",
                        "minimum": 1
                      },
                      "result_type": {
                        "type": "string",
                        "enum": [
                          "image",
                          "audio",
                          "video"
                        ]
                      },
                      "file_key": {
                        "type": "string",
                        "pattern": "^file_[a-f0-9]{32}$"
                      },
                      "reason": {
                        "type": "string",
                        "minLength": 1,
                        "maxLength": 4000
                      },
                      "request_id": {
                        "type": "string",
                        "pattern": "^[A-Za-z0-9:_-]{1,100}$"
                      }
                    },
                    "required": [
                      "id",
                      "version",
                      "result_type",
                      "file_key",
                      "reason",
                      "request_id"
                    ]
                  }
                ]
              }
            }
          }
        }
      }
    },
    "/adminapi/custom.ai-assistant.requestStatus": {
      "get": {
        "operationId": "aiAssistantRequestStatus",
        "tags": [
          "AiAssistant"
        ],
        "x-peanut-permissions": [
          "custom.ai-assistant.view"
        ],
        "x-peanut-errors": [
          "AI_INPUT_INVALID",
          "AI_PERMISSION_DENIED",
          "AI_NOT_FOUND",
          "AI_VERSION_CONFLICT",
          "AI_REQUEST_CONFLICT",
          "AI_SECRET_PROTECTOR_UNAVAILABLE",
          "AI_UNKNOWN_NO_RESEND",
          "SOURCE_AUTHORIZATION_BRIDGE_REQUIRED"
        ],
        "description": "仅原实际成员，在当前原动作和对象范围下核对同UUID的固定回执/准备锚点；未找到永不证明未执行，不读取或返回凭据。",
        "responses": {
          "200": {
            "description": "成功",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/AiRequestReceiptResponse"
                }
              }
            }
          },
          "400": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "401": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "403": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "404": {
            "$ref": "#/components/responses/ErrorResponse"
          },
          "409": {
            "$ref": "#/components/responses/ErrorResponse"
          }
        },
        "parameters": [
          {
            "name": "endpoint",
            "in": "query",
            "required": true,
            "schema": {
              "type": "string",
              "enum": [
                "saveConfiguration",
                "saveCredential",
                "configurationState",
                "createTask",
                "manualCandidate",
                "taskAction",
                "collectCandidate",
                "decide"
              ]
            }
          },
          {
            "name": "kind",
            "in": "query",
            "required": true,
            "schema": {
              "type": "string",
              "enum": [
                "connection",
                "model",
                "route",
                "template",
                "task"
              ]
            }
          },
          {
            "name": "id",
            "in": "query",
            "required": false,
            "schema": {
              "type": "integer",
              "minimum": 1
            }
          },
          {
            "name": "action",
            "in": "query",
            "required": false,
            "schema": {
              "type": "string",
              "enum": [
                "enable",
                "disable",
                "cancel",
                "retry",
                "poll",
                "observeUnknown"
              ]
            }
          },
          {
            "name": "request_id",
            "in": "query",
            "required": true,
            "schema": {
              "type": "string",
              "pattern": "^[A-Za-z0-9:_-]{1,100}$"
            }
          }
        ]
      }
    }
  },
  "components": {
    "schemas": {
      "AiRecord": {
        "type": "object",
        "additionalProperties": false,
        "properties": {
          "id": {
            "type": "integer",
            "minimum": 1
          },
          "title": {
            "type": "string"
          },
          "status": {
            "type": "string"
          },
          "version": {
            "type": "integer",
            "minimum": 1
          },
          "created_by": {
            "type": "integer",
            "minimum": 1
          },
          "details": {
            "type": "object",
            "additionalProperties": true
          },
          "created_at": {
            "type": "integer"
          },
          "updated_at": {
            "type": "integer"
          },
          "history": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/AiRecord"
            }
          },
          "history_next_before_id": {
            "type": "integer",
            "nullable": true
          },
          "native_task": {
            "type": "object",
            "additionalProperties": true
          }
        },
        "required": [
          "id",
          "title",
          "status",
          "version",
          "created_by",
          "details"
        ]
      },
      "AiRecordResponse": {
        "type": "object",
        "additionalProperties": false,
        "properties": {
          "code": {
            "type": "integer"
          },
          "msg": {
            "type": "string"
          },
          "data": {
            "$ref": "#/components/schemas/AiRecord"
          },
          "show": {
            "type": "integer"
          }
        },
        "required": [
          "data"
        ]
      },
      "AiObjectResponse": {
        "type": "object",
        "additionalProperties": false,
        "properties": {
          "code": {
            "type": "integer"
          },
          "msg": {
            "type": "string"
          },
          "data": {
            "type": "object",
            "additionalProperties": true
          },
          "show": {
            "type": "integer"
          }
        },
        "required": [
          "data"
        ]
      },
      "AiPageResponse": {
        "type": "object",
        "additionalProperties": false,
        "properties": {
          "code": {
            "type": "integer"
          },
          "msg": {
            "type": "string"
          },
          "data": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
              "list": {
                "type": "array",
                "items": {
                  "$ref": "#/components/schemas/AiRecord"
                }
              },
              "count": {
                "type": "integer"
              }
            },
            "required": [
              "list",
              "count"
            ]
          },
          "show": {
            "type": "integer"
          }
        },
        "required": [
          "data"
        ]
      },
      "AiHistoryResponse": {
        "type": "object",
        "additionalProperties": false,
        "properties": {
          "code": {
            "type": "integer"
          },
          "msg": {
            "type": "string"
          },
          "data": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
              "list": {
                "type": "array",
                "items": {
                  "$ref": "#/components/schemas/AiRecord"
                }
              },
              "next_before_id": {
                "type": "integer",
                "nullable": true
              }
            },
            "required": [
              "list",
              "next_before_id"
            ]
          },
          "show": {
            "type": "integer"
          }
        },
        "required": [
          "data"
        ]
      },
      "AiRequestReceipt": {
        "type": "object",
        "additionalProperties": false,
        "properties": {
          "request_id": {
            "type": "string"
          },
          "endpoint": {
            "type": "string"
          },
          "kind": {
            "type": "string"
          },
          "object_id": {
            "type": "integer",
            "nullable": true
          },
          "actor": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
              "tenant_id": {
                "type": "integer",
                "minimum": 1
              },
              "member_id": {
                "type": "integer",
                "minimum": 1
              }
            },
            "required": [
              "tenant_id",
              "member_id"
            ]
          },
          "state": {
            "type": "string",
            "enum": [
              "applied",
              "preparing",
              "unconfirmed"
            ]
          },
          "anchor_present": {
            "type": "boolean"
          },
          "evidence": {
            "type": "string"
          },
          "result": {
            "allOf": [
              {
                "$ref": "#/components/schemas/AiRecord"
              }
            ],
            "nullable": true
          },
          "absence_proves_not_executed": {
            "type": "boolean",
            "enum": [
              false
            ]
          },
          "provider_success": {
            "type": "boolean",
            "enum": [
              false
            ]
          }
        },
        "required": [
          "request_id",
          "endpoint",
          "kind",
          "object_id",
          "actor",
          "state",
          "anchor_present",
          "evidence",
          "result",
          "absence_proves_not_executed",
          "provider_success"
        ]
      },
      "AiRequestReceiptResponse": {
        "type": "object",
        "additionalProperties": false,
        "properties": {
          "data": {
            "$ref": "#/components/schemas/AiRequestReceipt"
          },
          "code": {
            "type": "integer"
          },
          "msg": {
            "type": "string"
          }
        },
        "required": [
          "data"
        ]
      }
    }
  }
}
JSON, true, 512, JSON_THROW_ON_ERROR);

// Additive, cold HTTP contracts for fixed candidate lifecycle and explicitly linked rework.
$positive = ['type' => 'integer','minimum' => 1];
$create = & $metadata['paths']['/adminapi/custom.ai-assistant.createTask']['post']['requestBody']['content']['application/json']['schema']['properties'];
$create['rework_of_task_id'] = $positive;
$create['rework_of_task_version'] = $positive;
unset($create);
$lifecycle = $metadata['paths']['/adminapi/custom.ai-assistant.decide']['post'];
$lifecycle['operationId'] = 'aiAssistantCandidateLifecycle';
$lifecycle['description'] = '当前 task.view/review 范围、原UUID及对象CAS；撤回只终止旧采纳可用性。重做先保留旧固定版本，再显式建立新Task；替代须指定另一当前已采纳任务及其版本，不自动批准制作或发起外部生成。';
$lifecycle['x-peanut-errors'] = array_merge($lifecycle['x-peanut-errors'], ['AI_ACCEPTANCE_STATE_INVALID','AI_ACCEPTED_BINDING_CHANGED','AI_REPLACEMENT_SOURCE_MISMATCH']);
$lifecycle['requestBody']['content']['application/json']['schema'] = [
    'type' => 'object','additionalProperties' => false,
    'properties' => ['id' => $positive,'version' => $positive,'action' => ['type' => 'string','enum' => ['withdraw','rework','supersede']],
        'reason' => ['type' => 'string','minLength' => 1,'maxLength' => 4000],
        'request_id' => ['type' => 'string','pattern' => '^[A-Za-z0-9:_-]{1,100}$'],
        'replacement_task_id' => $positive,'replacement_task_version' => $positive],
    'required' => ['id','version','action','reason','request_id'],
];
$metadata['components']['schemas']['AiRequestReceipt']['properties']['input_hash'] = ['type' => 'string','pattern' => '^[a-f0-9]{64}$','description' => 'candidateLifecycle 的持久Event原规范化五元组输入SHA；缺失不能确认本地原输入已执行'];
$lifecycle['responses'] = $metadata['paths']['/adminapi/custom.ai-assistant.requestStatus']['get']['responses'];
$metadata['paths']['/adminapi/custom.ai-assistant.candidateLifecycle']['post'] = $lifecycle;
foreach ($metadata['paths']['/adminapi/custom.ai-assistant.requestStatus']['get']['parameters'] as &$parameter) {
    if ($parameter['name'] === 'endpoint') {
        $parameter['schema']['enum'][] = 'candidateLifecycle';
    } elseif ($parameter['name'] === 'action' && isset($parameter['schema']['enum'])) {
        $parameter['schema']['enum'] = array_values(array_unique(array_merge($parameter['schema']['enum'], ['withdraw','rework','supersede'])));
    }
}
unset($parameter);
return $metadata;
