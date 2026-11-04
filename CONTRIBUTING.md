# Cómo trabajamos con Git

1. **Nadie sube directamente a `main`.** Todo entra por Pull Request.
2. Crea una rama corta para cada tarea, con tu nombre delante:
   - `amilivia/validacion-url`
   - `josemaria/comprobaciones-seo`
   - `adrian/proyectos`
3. Antes de empezar cada día: `git checkout main && git pull`, y actualiza tu rama con `git rebase main`.
4. Toca **solo tus carpetas** (ver README). Si necesitas cambiar algo de `src/Shared/` o `db/`, abre un PR aparte y avisa a los otros dos.
5. Cada PR lo revisa y aprueba **otro compañero** antes de fusionarlo:
   - PR de Amilivia → revisa José María
   - PR de José María → revisa Adrián
   - PR de Adrián → revisa Amilivia
6. Un PR se aprueba si funciona, tiene pruebas de lo nuevo y no incluye contraseñas ni el fichero `.env`.
7. Cambios en la base de datos: un fichero nuevo en `db/migrations/` con fecha y nombre (`2026_11_10_amilivia_tabla_x.sql`). Nunca se modifica uno ya fusionado.
8. PR pequeños y frecuentes mejor que uno enorme al final.
