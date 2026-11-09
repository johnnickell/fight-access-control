"""Render the bounded existing Feature metadata ownership view; no application execution."""
from pathlib import Path

from diagrams import Cluster, Diagram, Edge, Node

SOURCE = "46343392bfc8f4a0151424d82f863d1be80e2f69"
OUTPUT = Path(__file__).resolve().with_suffix("")

with Diagram(
    "Effective Feature metadata — existing implementation\nSource: " + SOURCE,
    filename=str(OUTPUT), show=False, direction="LR", outformat="png",
    graph_attr={"fontname": "DejaVu Sans", "fontsize": "16", "pad": "0.3", "nodesep": "0.6",
                "labeljust": "l", "bgcolor": "white"},
    node_attr={"fontname": "DejaVu Sans", "fontsize": "12", "shape": "box",
               "width": "4.1", "height": "1.1"},
    edge_attr={"fontname": "DejaVu Sans", "fontsize": "11"},
):
    with Cluster("Application / AccessControl", graph_attr={"fontname": "DejaVu Sans"}):
        selected = Node("Action/SelectedAction\npure association + ancestry", nodeid="selected")
        requirements = Node("Feature/FeatureRequirements\ncumulative interpretation", nodeid="requirements")
        attribute = Node("Feature/Attribute/FeatureFlag\nrepeatable class + method", nodeid="attribute")
        references = Node("Feature/Discovery/\nFeatureReferences\nvalidated distinct names", nodeid="references")
    requirements >> Edge(label="S1: reads sites / checks match") >> selected
    requirements >> Edge(label="S2: constructs native Attributes") >> attribute
    requirements >> Edge(label="S3: builds / merges references") >> references
    legend = Node("Legend: solid arrow = static reference\nConsumer → referenced type; not execution order\nScope: metadata only; no admission or scanner",
                  nodeid="legend", shape="note", width="4.5")
