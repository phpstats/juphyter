"""juphyter's Jupyter transport, a wrapper kernel over the PHP engine.

The Jupyter protocol is ipykernel's: ZeroMQ, five channels, HMAC signing, the
heartbeat and version negotiation. Everything PHP is a separate process behind
a unix domain socket. :class:`~juphyter_kernel.kernel.JuphyterKernel` is the
join between the two, and :mod:`juphyter_kernel.kernel` documents the contract.

This module is not a PyPI package. It ships inside the Composer package and is
reached through the ``PYTHONPATH`` that ``juphyter install`` writes into the
kernelspec, which starts it as::

    python -m juphyter_kernel -f {connection_file}
"""

from .kernel import Engine, EngineError, JuphyterKernel

#: Reported as ``implementation_version`` in `kernel_info_reply`. The PHP
#: version a kernel runs is the engine's to report, and is not this.
__version__ = "0.1.0"

__all__ = ["Engine", "EngineError", "JuphyterKernel", "__version__"]
