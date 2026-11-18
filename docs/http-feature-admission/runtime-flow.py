"""Render the existing bounded Feature request flow; see README.md for traces."""
from pathlib import Path

from diagrams import Cluster, Diagram, Edge, Node

REVISION = "d320838cf055cb57de76c284c544383188c30d6e"
OUTPUT = Path(__file__).with_suffix("")

with Diagram(
    "Feature admission | existing implementation\n"
    f"Source: {REVISION}\n"
    "Dotted arrows: ordered runtime interactions (not static dependencies)\n"
    "Selected native request only; storage, provider internals and bootstrap omitted",
    filename=str(OUTPUT), outformat="png", show=False, direction="TB",
    graph_attr={"fontname": "DejaVu Sans", "fontsize": "16", "bgcolor": "white",
                "pad": "0.35", "nodesep": "0.5", "ranksep": "0.65", "dpi": "120"},
    node_attr={"shape": "box", "style": "rounded,filled", "fillcolor": "white",
               "fontname": "DejaVu Sans", "fontsize": "12", "fontcolor": "#17212b",
               "width": "3.3", "height": "0.65", "fixedsize": "false"},
    edge_attr={"fontname": "DejaVu Sans", "fontsize": "11", "style": "dotted",
               "color": "#185b9d", "fontcolor": "#185b9d"},
):
    native = Node("Consumer native route\nSlim / Yii selected action", nodeid="native")
    with Cluster("Adapter | PSR-15", graph_attr={"bgcolor": "#eef4fa"}):
        gate = Node("FeatureMiddleware.process\nValidate exact selected action", nodeid="gate")
        scope = Node("RequestAuthentication.admit\nRequest-owned authority / finally scope", nodeid="scope")
        work = Node("Downstream handler\nProtected validators / action", nodeid="work")
    with Cluster("Application | Feature", graph_attr={"bgcolor": "#eff8ee"}):
        admission = Node("FeatureAdmission.admit\nConjunctive restriction, not authorization", nodeid="admission")
        availability = Node("FeatureAvailability.isAvailable\nFresh definitions / exact bound identity", nodeid="availability")
    common = Node("External Common safe boundary\nOne redacted reporting / presentation owner", nodeid="common")
    native >> Edge(label="1. trusted native association") >> gate
    gate >> Edge(label="2. enter scope") >> scope
    scope >> Edge(label="3. invoke gate callback with authority* ") >> admission
    admission >> Edge(label="4. check every required Feature") >> availability
    scope >> Edge(label="5. only after admission returns; sanitized context") >> work
    gate >> Edge(label="F. ordinary refusal 404 / fault 500*", color="#9b3829", fontcolor="#9b3829") >> common
