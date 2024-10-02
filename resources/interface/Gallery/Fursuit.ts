/**
 * API Item for the gallery used by the backend
 *
 * Fursuit interface for usage for the gallery items
 */
export interface Fursuit {
    /**
     * Database ID of the fursuit
     */
    id: number,
    /**
     * Name of the fursuit
     */
    name: string,
    /**
     * Species of the fursuit
     */
    species: string,
    /**
     * Image url of the fursuit (Temporary link)
     */
    image: string,
    /**
     * Fursuit got caught X times
     */
    scoring: number,
}

export default Fursuit;
