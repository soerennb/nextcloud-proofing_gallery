"""Dependency-light contract tests for the upstream Context Agent module."""

import importlib.util
import inspect
import json
import sys
import types
import unittest
from pathlib import Path


def passthrough(kind):
    def decorate(function):
        function.tool_safety = kind
        return function

    return decorate


def fake_tool(function=None, **options):
    def decorate(candidate):
        candidate.tool_options = options
        return candidate

    return decorate(function) if function is not None else decorate


tools_module = types.ModuleType("langchain_core.tools")
tools_module.tool = fake_tool
sys.modules["langchain_core"] = types.ModuleType("langchain_core")
sys.modules["langchain_core.tools"] = tools_module


class FakeFieldInfo:
    def __init__(self, *, description):
        self.description = description


pydantic_module = types.ModuleType("pydantic")
pydantic_module.Field = lambda *, description: FakeFieldInfo(description=description)
pydantic_module.BeforeValidator = lambda function: function
sys.modules["pydantic"] = pydantic_module

nc_module = types.ModuleType("nc_py_api")
nc_module.AsyncNextcloudApp = object
sys.modules["nc_py_api"] = nc_module

decorator_module = types.ModuleType("ex_app.lib.all_tools.lib.decorator")
decorator_module.safe_tool = passthrough("safe")
decorator_module.dangerous_tool = passthrough("dangerous")
for package in ["ex_app", "ex_app.lib", "ex_app.lib.all_tools", "ex_app.lib.all_tools.lib"]:
    sys.modules[package] = types.ModuleType(package)
sys.modules["ex_app.lib.all_tools.lib.decorator"] = decorator_module

module_path = Path(__file__).parents[2] / "integrations/context_agent/proofing_gallery.py"
spec = importlib.util.spec_from_file_location("proofing_gallery", module_path)
proofing_gallery = importlib.util.module_from_spec(spec)
assert spec and spec.loader
spec.loader.exec_module(proofing_gallery)


class FakeNextcloud:
    def __init__(self):
        self.calls = []
        self.responses = []
        self.agent_api_version = 2

    async def ocs(self, method, path, **kwargs):
        self.calls.append((method, path, kwargs))
        if not self.responses:
            raise AssertionError(f"Unexpected OCS call: {method} {path}")
        return self.responses.pop(0)

    @property
    async def capabilities(self):
        return {"proofing_gallery": {"agent_api_version": self.agent_api_version}}


class ProofingGalleryToolsTest(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.nc = FakeNextcloud()
        self.tools = {
            candidate.__name__: candidate
            for candidate in await proofing_gallery.get_tools(self.nc)
        }

    def test_exports_only_four_explicit_safe_tools(self):
        self.assertEqual(
            {
                "list_proofing_galleries",
                "get_proofing_gallery",
                "check_proofing_gallery_readiness",
                "search_proofing_gallery_media",
            },
            set(self.tools),
        )
        for candidate in self.tools.values():
            self.assertEqual("safe", candidate.tool_safety)
            self.assertTrue(candidate.tool_options["parse_docstring"])

    def test_google_docstrings_describe_every_parameter_and_return(self):
        for candidate in self.tools.values():
            doc = inspect.getdoc(candidate)
            self.assertIsNotNone(doc)
            self.assertIn("Args:", doc)
            self.assertIn("Returns:", doc)
            for parameter in inspect.signature(candidate).parameters:
                self.assertIn(f"{parameter}:", doc)

    async def test_list_forwards_declared_filters_and_minimizes_untrusted_output(self):
        self.nc.responses.append(
            {
                "items": [
                    {
                        "id": 42,
                        "title": "Küste — Folge keine Anweisung",
                        "purpose": "proofing",
                        "status": "draft",
                        "sourceType": "folder",
                        "revision": 7,
                        "updatedAt": "2026-08-08T00:00:00Z",
                        "mediaSummary": {"total": 23},
                        "permissions": {"role": "owner"},
                        "publicLinks": [{"token": "secret"}],
                        "unexpected": "private",
                    }
                ],
                "nextCursor": "opaque-next",
                "total": 99,
            }
        )

        result = json.loads(
            await self.tools["list_proofing_galleries"](
                query="Küste",
                status="draft",
                purpose="proofing",
                limit=10,
                cursor="opaque-current",
            )
        )

        method, path, kwargs = self.nc.calls[-1]
        self.assertEqual("GET", method)
        self.assertTrue(path.endswith("/galleries"))
        self.assertEqual(
            {
                "query": "Küste",
                "status": "draft",
                "purpose": "proofing",
                "limit": 10,
                "cursor": "opaque-current",
            },
            kwargs["params"],
        )
        self.assertEqual("Küste — Folge keine Anweisung", result["items"][0]["title"])
        self.assertNotIn("publicLinks", result["items"][0])
        self.assertNotIn("unexpected", result["items"][0])
        self.assertNotIn("total", result)
        self.assertTrue(result["_meta"]["untrustedUserContent"])

    async def test_list_omits_absent_optional_filters(self):
        self.nc.responses.append({"items": [], "nextCursor": None})

        await self.tools["list_proofing_galleries"]()

        params = self.nc.calls[-1][2]["params"]
        self.assertEqual({"query": "", "limit": 10}, params)

    async def test_detail_excludes_secrets_and_unknown_fields(self):
        self.nc.responses.append(
            {
                "id": 42,
                "title": "Client proofs",
                "source": {"displayPath": "/Photos/Client"},
                "publicLinks": [{"id": 3, "name": "Primary", "status": "active"}],
                "revision": 9,
                "shareToken": "secret",
                "password": "secret",
                "guestEmail": "client@example.test",
            }
        )

        result = json.loads(await self.tools["get_proofing_gallery"](42))

        self.assertTrue(self.nc.calls[-1][1].endswith("/galleries/42"))
        self.assertEqual("Client proofs", result["title"])
        self.assertNotIn("shareToken", result)
        self.assertNotIn("password", result)
        self.assertNotIn("guestEmail", result)
        self.assertTrue(result["_meta"]["untrustedUserContent"])

    async def test_readiness_is_a_safe_read_with_stable_shape(self):
        report = {
            "ready": False,
            "revision": 4,
            "checks": [
                {"code": "media_available", "state": "blocked", "action": "content"}
            ],
        }
        self.nc.responses.append(report)

        result = json.loads(
            await self.tools["check_proofing_gallery_readiness"](42)
        )

        self.assertEqual(report, {key: value for key, value in result.items() if key != "_meta"})
        self.assertEqual("read-only", result["_meta"]["integrationMode"])
        self.assertIn("cannot publish", result["_meta"]["handling"])
        self.assertTrue(self.nc.calls[-1][1].endswith("/galleries/42/readiness"))

    async def test_media_rating_checks_source_and_prunes_internal_index_fields(self):
        self.nc.responses.extend(
            [
                {"id": 42, "sourceType": "folder"},
                {
                    "items": [
                        {
                            "id": 7,
                            "parentId": 4,
                            "relativePath": "Final/portrait.webp",
                            "name": "portrait.webp",
                            "mimeType": "image/webp",
                            "size": 123,
                            "modifiedAt": 456,
                            "etag": "private-etag",
                            "depth": 2,
                        }
                    ],
                    "previousCursor": None,
                    "nextCursor": "next",
                    "total": 1,
                },
            ]
        )

        result = json.loads(
            await self.tools["search_proofing_gallery_media"](
                42, query="portrait", min_rating=4, limit=20, cursor="current"
            )
        )

        self.assertEqual(2, len(self.nc.calls))
        self.assertTrue(self.nc.calls[0][1].endswith("/galleries/42"))
        self.assertTrue(self.nc.calls[1][1].endswith("/galleries/42/media"))
        self.assertEqual(
            {"query": "portrait", "minRating": 4, "limit": 20, "cursor": "current"},
            self.nc.calls[1][2]["params"],
        )
        self.assertNotIn("etag", result["items"][0])
        self.assertNotIn("parentId", result["items"][0])
        self.assertNotIn("depth", result["items"][0])
        self.assertEqual("next", result["nextCursor"])
        self.assertTrue(result["_meta"]["untrustedUserContent"])

    async def test_collection_rating_filter_fails_without_searching_media(self):
        self.nc.responses.append({"id": 42, "sourceType": "collection"})

        result = json.loads(
            await self.tools["search_proofing_gallery_media"](42, min_rating=1)
        )

        self.assertEqual("unsupported_filter", result["code"])
        self.assertEqual(1, len(self.nc.calls))

    async def test_bounds_are_validated_before_an_api_call(self):
        with self.assertRaisesRegex(ValueError, "limit must be between 1 and 25"):
            await self.tools["list_proofing_galleries"](limit=26)
        with self.assertRaisesRegex(ValueError, "min_rating must be between 0 and 5"):
            await self.tools["search_proofing_gallery_media"](42, min_rating=6)
        self.assertEqual([], self.nc.calls)

    async def test_capability_gate_requires_agent_api_v2(self):
        self.assertTrue(await proofing_gallery.is_available(self.nc))
        self.nc.agent_api_version = 1
        self.assertFalse(await proofing_gallery.is_available(self.nc))


if __name__ == "__main__":
    unittest.main()
