# Sistema de diseno

Portado de TRAFODEX (`resources/css/app.css`), que imita la paleta **SAP Fiori Quartz** con CSS
propio. Aclaracion util: TRAFODEX **no** usa SAPUI5 ni `fundamental-styles`; usa Ant Design Vue con
CSS encima. Lo que se replica aqui es la paleta y las convenciones de composicion, que es lo que da
el aspecto reconocible.

## Tokens

Definidos en `app/assets/stylesheets/tokens.css`. Los principales:

| Token | Valor | Uso |
| --- | --- | --- |
| `--color-primary` | `#0A6ED1` | acciones primarias, foco, enlaces |
| `--color-shell-bar` | `#354A5F` | barra superior |
| `--color-sidebar-bg` | derivado de la barra | menu lateral |
| `--color-page-bg` | `#e9edf2` | fondo de pagina |
| `--color-surface` | `#ffffff` | tarjetas y tablas |
| `--color-surface-hover` / `--color-surface-selected` | `#F5F9FE` / `#E6F1FB` | filas |
| `--color-danger` | `#BB0000` | errores y riesgo alto |
| `--color-text` / `--color-text-muted` | `#32363A` / `#6A6D70` | texto |
| `--font-sans` | Inter | tipografia |

Ocho esquemas conmutables (`sap`, `slate`, `emerald`, `indigo`, `red`, `amber`, `teal`, `contrast`)
via `html[data-scheme]`, mas modo oscuro. El esquema se elige por pais en `settings.theme_scheme`.

## Reglas de composicion

1. Fondo de pagina gris azulado, contenido en tarjetas blancas.
2. Franja de titulo blanca a sangre completa (`.page-header` con margen negativo).
3. Cabecera de tabla gris sutil, en mayusculas y pequena.
4. Barra de acciones fija al pie en formularios y en seleccion multiple (`.action-bar`).
5. Radios de 10 px en tarjetas, 16 px en contenedores grandes; sombras casi imperceptibles.
6. El color nunca es el unico portador de significado: los estados llevan texto o icono ademas del
   color, porque los formatos se imprimen en blanco y negro.

## Que no hacer

- Estilos en linea (`style="..."`) como en la v1, donde el color de la cabecera venia interpolado
  desde la base en cada vista.
- Colores en crudo: siempre un token.
- Depender de assets fuera del repositorio: la v1 cargaba AdminLTE desde `/AdminLTE-3.2.0/`, una
  carpeta que no esta versionada, asi que el despliegue no era reproducible.
