# Context: Unraid AI Cli Agents

Integration of the AI Cli Agents into the Unraid WebUI for AI-assisted server management.

## Project Specifics
- **Architecture**: PHP Native (Neuron AI Framework).
- **Backend**: AICliAgents CLI / PHP.
- **State**: HITL (Human-in-the-Loop).

## Local Reference
- **Project Context**: `docs/architecture.md`
- **User Guide**: `README.public.md`

## Development Workflow
- **Standard**: `activate_skill unraid-plugin`
- **Staging/Testing**: `activate_skill unraid-factory` (**MANDATORY** for GitLab commits).
- **Public Release**: `activate_skill unraid-storefront`