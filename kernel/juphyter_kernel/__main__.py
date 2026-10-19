"""The kernelspec's entry point: ``python -m juphyter_kernel -f <file>``.

``IPKernelApp`` reads the connection file, binds the five ZeroMQ sockets and
runs the event loop. Its ``kernel_class`` is how a kernel other than IPython's
own is put behind it, and the command line belongs to the app rather than to
this module.
"""

from ipykernel.kernelapp import IPKernelApp

from .kernel import JuphyterKernel

if __name__ == "__main__":
    IPKernelApp.launch_instance(kernel_class=JuphyterKernel)
