# Thin wrapper around scripts/task.py so Linux/CI users can type `make ci` and Windows users
# (no make installed) can type `python scripts/task.py ci`. Both run identical steps.
PY ?= python3
PHASE ?=

.PHONY: up down doctor lint arch test modules invariants web-lint web-build e2e ci gate

up:         ; $(PY) scripts/task.py up
down:       ; $(PY) scripts/task.py down
doctor:     ; $(PY) scripts/task.py doctor
lint:       ; $(PY) scripts/task.py lint
arch:       ; $(PY) scripts/task.py arch
test:       ; $(PY) scripts/task.py test $(GROUPS)
modules:    ; $(PY) scripts/task.py modules
invariants: ; $(PY) scripts/task.py invariants
web-lint:   ; $(PY) scripts/task.py web-lint
web-build:  ; $(PY) scripts/task.py web-build
e2e:        ; $(PY) scripts/task.py e2e $(PROJECTS)
ci:         ; $(PY) scripts/task.py ci
gate:       ; $(PY) scripts/task.py gate $(PHASE)
