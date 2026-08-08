"""Dependency-light contract tests for the upstream Context Agent module."""

import importlib.util
import sys
import types
import unittest
from pathlib import Path


def passthrough(kind):
    def decorate(function):
        function.tool_safety = kind
        return function

    return decorate


tools_module = types.ModuleType("langchain_core.tools")
tools_module.tool = lambda function: function
sys.modules["langchain_core"] = types.ModuleType("langchain_core")
sys.modules["langchain_core.tools"] = tools_module

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

    async def ocs(self, method, path, **kwargs):
        self.calls.append((method, path, kwargs))
        return {"ok": True}

    @property
    async def capabilities(self):
        return {"proofing_gallery": {"agent_api_version": 2}}


class ProofingGalleryToolsTest(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.nc = FakeNextcloud()
        self.tools = {tool.__name__: tool for tool in await proofing_gallery.get_tools(self.nc)}

    async def test_read_tool_keeps_filters_and_current_user_session(self):
        await self.tools["list_customer_galleries"](query="wedding", limit=10)

        method, path, kwargs = self.nc.calls[-1]
        self.assertEqual("GET", method)
        self.assertTrue(path.endswith("/galleries"))
        self.assertEqual("wedding", kwargs["params"]["query"])
        self.assertEqual("safe", self.tools["list_customer_galleries"].tool_safety)

    async def test_mutation_includes_revision_and_unique_idempotency_keys(self):
        tool = self.tools["rename_customer_gallery"]
        await tool(42, "First", 7)
        await tool(42, "Second", 8)

        first = self.nc.calls[-2][2]["json"]
        second = self.nc.calls[-1][2]["json"]
        self.assertEqual(7, first["changes"]["expectedRevision"])
        self.assertNotEqual(first["requestId"], second["requestId"])
        self.assertEqual("dangerous", tool.tool_safety)

    async def test_capability_gate_requires_agent_api_v2(self):
        self.assertTrue(await proofing_gallery.is_available(self.nc))


if __name__ == "__main__":
    unittest.main()
